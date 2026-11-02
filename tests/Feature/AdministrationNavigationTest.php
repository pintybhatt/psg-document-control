<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AdministrationNavigationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function pageProps(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertSame(1, preg_match('/data-props="([^"]+)"/', $response->getContent(), $matches));

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestWith(['DOCUMENT_CONTROLLER'])]
    #[TestWith(['DEPARTMENT_USER'])]
    #[TestWith(['VIEWER'])]
    public function test_operational_roles_keep_their_workspaces_without_administration_navigation_or_direct_access(string $code): void
    {
        $this->withoutVite();
        $this->definitions();
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $role = Role::query()->where('code', $code)->sole();
        $keys = PermissionCatalog::recommendations()[$code]['permission_keys'];
        app(RbacMutation::class)->run(fn () => $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all()));
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => $role->id, 'scope_id' => $scope->id, 'active' => true]);
        $department = null;
        if ($code === 'DEPARTMENT_USER') {
            $department = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
            OrganizationMembership::factory()->for($actor)->inDepartment($department)->create();
        } else {
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        }
        $this->signIn($actor);
        $this->assertFalse(app(AccessControl::class)->canAccessAdministration($actor));
        foreach (['dashboard', 'incoming', 'outgoing', 'controlled-documents', 'document-search', 'reports'] as $page) {
            $props = $this->pageProps($this->get('/'.$page));
            $this->assertSame(['dashboard', 'incoming', 'outgoing', 'controlled-documents', 'document-search', 'reports'], array_column($props['navigation'], 'key'));
            $this->assertNull($props['administration']);
        }
        $this->get('/administration')->assertForbidden()->assertDontSee('id="dcms-app"', false);
        $this->getJson('/administration')->assertForbidden();
        $this->getJson('/api/v1/authorization/contexts?administration=1')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson($this->scopedUrl('roles', $scope))->assertForbidden();
        $this->postJson('/api/v1/roles', ['scope_type' => 'site', 'company_id' => $site->company_id, 'site_id' => $site->id])->assertForbidden();
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $site->id)->assertJsonPath('data.0.department_id', $department?->id);
        $this->assertEqualsCanonicalizing($keys, $role->permissions()->pluck('key')->all());
        $this->assertDatabaseCount('user_role_assignments', 1);
    }

    public function test_system_administrator_keeps_administration_navigation_and_direct_access(): void
    {
        $this->withoutVite();
        $this->definitions();
        $actor = User::factory()->create();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole()->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->signIn($actor);
        $props = $this->pageProps($this->get('/administration'));
        $this->assertContains('administration', array_column($props['navigation'], 'key'));
        $this->assertStringContainsString('administration=1', $props['administration']['contexts']);
        $this->getJson($props['administration']['contexts'])->assertOk()->assertJsonPath('can_manage_organization', true);
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    #[TestWith(['roles.view', 'roles'])]
    #[TestWith(['assignments.view', 'role-assignments'])]
    #[TestWith(['memberships.view', 'memberships'])]
    #[TestWith(['master-data.view', 'document-master-data/categories'])]
    #[TestWith(['numbering.view', 'numbering-series'])]
    public function test_limited_custom_permissions_show_administration_and_retain_endpoint_boundaries(string $permission, string $endpoint): void
    {
        $this->withoutVite();
        $this->definitions();
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->grant($actor, $scope, [$permission]);
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->signIn($actor);
        $props = $this->pageProps($this->get('/administration'));
        $this->assertContains('administration', array_column($props['navigation'], 'key'));
        $this->getJson('/api/v1/authorization/contexts?administration=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.permissions', [$permission]);
        $this->getJson($this->scopedUrl($endpoint, $scope))->assertOk();
        $this->postJson('/api/v1/roles', $this->scopeData($scope))->assertForbidden();
        $this->getJson('/api/v1/user-management')->assertForbidden();
        $this->getJson('/api/v1/organization/companies')->assertForbidden();
    }

    #[TestWith(['storage-locations.manage'])]
    #[TestWith(['documents.audit.view'])]
    #[TestWith(['security.audit.view'])]
    #[TestWith(['users.view'])]
    #[TestWith(['users.manage'])]
    #[TestWith(['roles.manage'])]
    public function test_permissions_without_an_available_administration_section_do_not_enable_navigation(string $permission): void
    {
        $this->withoutVite();
        $this->definitions();
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $this->grant($actor, AuthorizationScope::context('site', $site->company_id, $site->id), [$permission]);
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->signIn($actor);
        $props = $this->pageProps($this->get('/dashboard'));
        $this->assertNotContains('administration', array_column($props['navigation'], 'key'));
        $this->get('/administration')->assertForbidden();
    }

    public function test_membership_and_permission_revocation_remove_navigation_in_the_existing_session(): void
    {
        $this->withoutVite();
        $this->definitions();
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $role = $this->grant($actor, $scope, ['master-data.view']);
        $membership = OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->signIn($actor);
        $this->get('/administration')->assertOk();
        $membership->update(['active' => false]);
        $this->get('/administration')->assertForbidden();
        $props = $this->pageProps($this->get('/dashboard'));
        $this->assertNotContains('administration', array_column($props['navigation'], 'key'));
        $membership->update(['active' => true]);
        $this->get('/administration')->assertOk();
        app(RbacMutation::class)->run(fn () => $role->permissions()->detach());
        $this->get('/administration')->assertForbidden();
    }

    public function test_administration_context_filter_preserves_operational_contexts_and_excludes_empty_management_scopes(): void
    {
        $this->withoutVite();
        $this->definitions();
        $first = Site::factory()->create();
        $second = Site::factory()->create(['company_id' => $first->company_id]);
        $actor = User::factory()->create();
        $this->grant($actor, AuthorizationScope::context('site', $first->company_id, $first->id), ['organization.access', 'documents.metadata.view']);
        $this->grant($actor, AuthorizationScope::context('site', $second->company_id, $second->id), ['numbering.view']);
        OrganizationMembership::factory()->for($actor)->atSite($first)->create();
        OrganizationMembership::factory()->for($actor)->atSite($second)->create();
        $this->signIn($actor);
        $this->getJson('/api/v1/authorization/contexts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/authorization/contexts?administration=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $second->id);
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $first->id);
        $this->get('/administration')->assertOk();
    }
}
