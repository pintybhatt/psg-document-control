<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageRoles;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RoleRecommendationsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function administrator(): User
    {
        $this->definitions();
        $actor = User::factory()->create();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole()->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->signIn($actor);

        return $actor;
    }

    /** @return list<string> */
    private function recommendation(string $code): array
    {
        return $this->getJson('/api/v1/permissions?scope_type=global')->assertOk()->json('recommendations.'.$code.'.permission_keys');
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function roleData(Role $role, array $keys): array
    {
        return ['scope_type' => 'global', 'name_en' => $role->name_en, 'name_lo' => $role->name_lo, 'description' => $role->description, 'active' => $role->active, 'permission_keys' => $keys];
    }

    #[TestWith(['SECURITY_ADMINISTRATOR'])]
    #[TestWith(['DOCUMENT_CONTROLLER'])]
    #[TestWith(['DEPARTMENT_USER'])]
    #[TestWith(['VIEWER'])]
    public function test_recommendations_are_read_only_implemented_and_have_appropriate_privilege_boundaries(string $code): void
    {
        $this->administrator();
        $role = Role::query()->where('code', 'VIEWER')->sole();
        app(RbacMutation::class)->run(fn () => $role->permissions()->sync(Permission::query()->where('key', 'incoming.operations.reopen')->pluck('id')->all()));
        $before = Role::query()->with('permissions')->orderBy('id')->get()->toArray();
        $response = $this->getJson('/api/v1/permissions?scope_type=global')->assertOk();
        $keys = $response->json('recommendations.'.$code.'.permission_keys');
        $this->assertNotEmpty($keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        foreach ($keys as $key) {
            $this->assertTrue(PermissionCatalog::available($key), $key);
            $this->assertDatabaseHas('permissions', ['key' => $key, 'active' => true]);
        }
        if ($code === 'SECURITY_ADMINISTRATOR') {
            $response->assertJsonPath('recommendations.'.$code.'.scope_types', ['global']);
            $this->assertContains('roles.manage', $keys);
            $this->assertContains('clearances.highly-confidential.manage', $keys);
        } else {
            foreach (['organization.access', 'documents.metadata.view', 'documents.content.preview', 'documents.attachments.download'] as $key) {
                $this->assertContains($key, $keys);
            }
            foreach (['users.manage', 'roles.manage', 'assignments.manage', 'memberships.manage', 'security.audit.view', 'document-access.manage', 'clearances.confidential.manage', 'clearances.highly-confidential.manage'] as $key) {
                $this->assertNotContains($key, $keys);
            }
            if ($code === 'DOCUMENT_CONTROLLER') {
                $this->assertContains('numbering.issue', $keys);
                $this->assertContains('controlled.release', $keys);
            } else {
                $this->assertNotContains('numbering.issue', $keys);
                $this->assertNotContains('controlled.register', $keys);
                $this->assertNotContains('controlled.release', $keys);
            }
            if ($code === 'VIEWER') {
                foreach ($keys as $key) {
                    $this->assertDoesNotMatchRegularExpression('/\.(manage|register|release|reopen)$/', $key);
                }
            } elseif ($code === 'DEPARTMENT_USER') {
                $this->assertContains('incoming.drafts.manage', $keys);
                $this->assertContains('controlled.drafts.manage', $keys);
            }
        }
        $this->assertSame($before, Role::query()->with('permissions')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['DOCUMENT_CONTROLLER'])]
    #[TestWith(['DEPARTMENT_USER'])]
    #[TestWith(['VIEWER'])]
    public function test_recommendations_can_be_manually_adjusted_and_saved_through_the_existing_role_api(string $code): void
    {
        $this->administrator();
        $role = Role::factory()->create(['scope_id' => AuthorizationScope::context('global')->id]);
        $keys = array_values(array_diff([...$this->recommendation($code), 'incoming.operations.reopen'], ['reports.view']));
        $this->patchJson('/api/v1/roles/'.$role->id, $this->roleData($role, $keys))->assertOk();
        $this->assertEqualsCanonicalizing($keys, $role->permissions()->pluck('key')->all());
        $this->assertDatabaseHas('rbac_audits', ['subject_id' => $role->id, 'subject_type' => 'role', 'action' => 'role.saved']);
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_system_administrator_recommendation_does_not_bypass_self_role_protection(): void
    {
        $this->administrator();
        $role = Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole();
        $before = $role->permissions()->pluck('key')->all();
        $this->patchJson('/api/v1/roles/'.$role->id, $this->roleData($role, $this->recommendation($role->code)))->assertForbidden();
        $this->assertSame($before, $role->permissions()->pluck('key')->all());
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_role_listing_replaces_only_the_obsolete_seed_description(): void
    {
        $this->administrator();
        $role = Role::query()->where('code', 'VIEWER')->sole();
        $legacy = 'Document capabilities remain unavailable until their operations are implemented.';
        $role->update(['description' => $legacy]);
        $url = '/api/v1/roles/'.$role->id.'?scope_type=global';
        $this->getJson($url)->assertOk()->assertJsonPath('data.description', 'Reads and searches authorized documents and reports.');
        $this->assertSame($legacy, $role->refresh()->description);
        $role->update(['description' => 'Custom role description']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.description', 'Custom role description');
    }

    public function test_recommended_permissions_do_not_bypass_scope_ownership_or_delegation(): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $role = Role::factory()->create(['scope_id' => AuthorizationScope::context('global')->id]);
        $keys = $this->recommendation('DOCUMENT_CONTROLLER');
        $this->patchJson('/api/v1/roles/'.$role->id, array_replace($this->roleData($role, $keys), $this->scopeData($scope)))->assertForbidden();
        $this->assertSame(0, $role->permissions()->count());
        $actor = User::factory()->create();
        $this->grant($actor, AuthorizationScope::context('global'), ['roles.view', 'roles.manage']);
        $this->signIn($actor);
        $this->getJson('/api/v1/permissions?scope_type=global')->assertOk();
        $this->patchJson('/api/v1/roles/'.$role->id, $this->roleData($role, $keys))->assertForbidden();
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_unavailable_permissions_remain_unavailable_even_when_recommended(): void
    {
        $this->administrator();
        Permission::query()->where('key', 'documents.attachments.download')->sole()->update(['active' => false]);
        $role = Role::factory()->create(['scope_id' => AuthorizationScope::context('global')->id]);
        $response = $this->getJson('/api/v1/permissions?scope_type=global')->assertOk();
        $entry = collect($response->json('data'))->firstWhere('key', 'documents.attachments.download');
        $this->assertFalse($entry['available']);
        $keys = $response->json('recommendations.VIEWER.permission_keys');
        $this->patchJson('/api/v1/roles/'.$role->id, $this->roleData($role, $keys))->assertUnprocessable()->assertJsonValidationErrors('permission_keys');
        $this->assertSame(0, $role->permissions()->count());
    }

    #[TestWith(['SECURITY_ADMINISTRATOR'])]
    #[TestWith(['DOCUMENT_CONTROLLER'])]
    #[TestWith(['DEPARTMENT_USER'])]
    #[TestWith(['VIEWER'])]
    public function test_standard_roles_are_global_and_protected_from_edits_duplicates_and_deletion(string $code): void
    {
        $this->administrator();
        $role = Role::query()->where('code', $code)->sole();
        $defaults = PermissionCatalog::standardRoleDefaults($code);
        $this->assertEqualsCanonicalizing($defaults, $role->permissions()->pluck('key')->all());
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->getJson($this->scopedUrl('roles', $scope))->assertOk()
            ->assertJsonFragment(['code' => $code, 'scope_key' => 'global', 'standard' => true, 'editable' => false]);
        $this->patchJson('/api/v1/roles/'.$role->id, $this->roleData($role, []))->assertForbidden();
        $this->postJson('/api/v1/roles', array_replace($this->roleData($role, []) + ['code' => $code], $this->scopeData($scope)))
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        try {
            $role->delete();
            $this->fail('Standard role deletion succeeded.');
        } catch (ValidationException $exception) {
            $this->assertSame(['rbacStandardRoleProtected'], $exception->errors()['role']);
        }
        $this->assertModelExists($role);
        $this->assertDatabaseCount('roles', 4);
        $this->assertEqualsCanonicalizing($defaults, $role->permissions()->pluck('key')->all());
    }

    #[TestWith(['DOCUMENT_CONTROLLER'])]
    #[TestWith(['DEPARTMENT_USER'])]
    #[TestWith(['VIEWER'])]
    public function test_operational_defaults_preserve_membership_and_confidentiality_enforcement(string $code): void
    {
        $actor = $this->administrator();
        $role = Role::query()->where('code', $code)->sole();
        $site = Site::factory()->create();
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        app(ManageRoles::class)->assign($actor, AuthorizationScope::context('site', $site->company_id, $site->id), $reader, $role);
        foreach ([[Confidentiality::Normal, true], [Confidentiality::Confidential, false], [Confidentiality::HighlyConfidential, false]] as [$classification, $allowed]) {
            $profile = DocumentSecurityProfile::factory()->atSite($site)->classified($classification)->create();
            foreach ([DocumentOperation::Metadata, DocumentOperation::Preview, DocumentOperation::Download] as $operation) {
                $this->assertSame($allowed, app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($profile), $operation));
            }
        }
        $foreign = DocumentSecurityProfile::factory()->create();
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($foreign), DocumentOperation::Metadata));
        if ($code === 'VIEWER') {
            $this->signIn($reader);
            $scope = ['company_id' => $site->company_id, 'site_id' => $site->id];
            $this->getJson('/api/v1/incoming-correspondence?'.http_build_query($scope))->assertOk();
            $this->postJson('/api/v1/incoming-correspondence', $scope)->assertForbidden();
            $this->assertDatabaseCount('incoming_correspondences', 0);
        }
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
    }
}
