<?php

namespace Tests\Feature;

use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class SecurityEndpointBoundaryTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{scope: AuthorizationScope, foreign_scope: AuthorizationScope, user: User, role: Role, membership: OrganizationMembership} */
    private function boundary(bool $administrator): array
    {
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $foreignScope = AuthorizationScope::context('site', $foreign->company_id, $foreign->id)->persist();
        $actor = User::factory()->create();
        if ($administrator) {
            $this->grant($actor, $scope, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS]);
        }
        $user = User::factory()->create();
        $role = $this->grant($user, $foreignScope, []);
        $membership = OrganizationMembership::factory()->for($user)->atSite($foreign)->create();
        $this->signIn($actor);

        return ['scope' => $scope, 'foreign_scope' => $foreignScope, 'user' => $user, 'role' => $role, 'membership' => $membership];
    }

    /** @return array<string, mixed> */
    private function roleUpdate(AuthorizationScope $scope): array
    {
        return $this->scopeData($scope) + ['name_en' => 'Reviewed role', 'name_lo' => 'ບົດບາດຕົວຢ່າງ', 'active' => true, 'permission_keys' => []];
    }

    #[TestWith(['roles', 'GET'])]
    #[TestWith(['roles', 'PATCH'])]
    #[TestWith(['role-assignments', 'DELETE'])]
    #[TestWith(['memberships', 'PATCH'])]
    #[TestWith(['memberships', 'DELETE'])]
    public function test_unprivileged_requests_return_403_before_record_lookup(string $resource, string $method): void
    {
        $fixture = $this->boundary(false);
        $id = match ($resource) {
            'roles' => $fixture['role']->id, 'role-assignments' => $fixture['role']->assignments()->sole()->id, default => $fixture['membership']->id
        };
        $data = $resource === 'roles' ? $this->roleUpdate($fixture['scope']) : $this->scopeData($fixture['scope']) + ['active' => false];

        $existing = $this->json($method, '/api/v1/'.$resource.'/'.$id, $data)->assertForbidden();
        $missing = $this->json($method, '/api/v1/'.$resource.'/999999999', $data)->assertForbidden();

        $this->assertSame($existing->json(), $missing->json());
    }

    #[TestWith(['roles', 'GET'])]
    #[TestWith(['roles', 'PATCH'])]
    #[TestWith(['role-assignments', 'DELETE'])]
    #[TestWith(['memberships', 'PATCH'])]
    #[TestWith(['memberships', 'DELETE'])]
    public function test_foreign_and_missing_records_return_the_same_404_to_scoped_administrators(string $resource, string $method): void
    {
        $fixture = $this->boundary(true);
        $id = match ($resource) {
            'roles' => $fixture['role']->id, 'role-assignments' => $fixture['role']->assignments()->sole()->id, default => $fixture['membership']->id
        };
        $data = $resource === 'roles' ? $this->roleUpdate($fixture['scope']) : $this->scopeData($fixture['scope']) + ['active' => false];

        $foreign = $this->json($method, '/api/v1/'.$resource.'/'.$id, $data)->assertNotFound();
        $missing = $this->json($method, '/api/v1/'.$resource.'/999999999', $data)->assertNotFound();

        $this->assertSame($foreign->json(), $missing->json());
        $this->assertTrue($fixture['membership']->refresh()->active);
        $this->assertDatabaseCount('rbac_audits', 0);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    #[TestWith(['role-assignments'])]
    #[TestWith(['memberships'])]
    public function test_assignment_validation_does_not_reveal_foreign_user_existence(string $endpoint): void
    {
        $fixture = $this->boundary(true);
        $scope = $fixture['scope'];
        $data = $this->scopeData($scope) + ($endpoint === 'memberships'
            ? ['unit_type' => 'site', 'unit_company_id' => $scope->company_id, 'unit_site_id' => $scope->site_id]
            : ['role_id' => Role::query()->where('code', 'VIEWER')->sole()->id]);

        $foreign = $this->postJson('/api/v1/'.$endpoint, $data + ['user_id' => $fixture['user']->id])->assertUnprocessable();
        $missing = $this->postJson('/api/v1/'.$endpoint, $data + ['user_id' => 999999999])->assertUnprocessable();

        $this->assertSame($foreign->json(), $missing->json());
        $foreign->assertJsonValidationErrors('user_id');
    }

    public function test_assignment_validation_does_not_reveal_foreign_role_existence(): void
    {
        $fixture = $this->boundary(true);
        $scope = $fixture['scope'];
        $local = OrganizationMembership::factory()->atSite(Site::query()->findOrFail($scope->site_id))->create();
        $data = $this->scopeData($scope) + ['user_id' => $local->user_id];

        $foreign = $this->postJson('/api/v1/role-assignments', $data + ['role_id' => $fixture['role']->id])->assertUnprocessable();
        $missing = $this->postJson('/api/v1/role-assignments', $data + ['role_id' => 999999999])->assertUnprocessable();

        $this->assertSame($foreign->json(), $missing->json());
        $foreign->assertJsonValidationErrors('role_id');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_unauthorized_scope_responses_do_not_reveal_company_existence(bool $administrator): void
    {
        $fixture = $this->boundary($administrator);
        $known = '/api/v1/roles?scope_type=company&company_id='.$fixture['foreign_scope']->company_id;

        $existing = $this->getJson($known)->assertForbidden();
        $missing = $this->getJson('/api/v1/roles?scope_type=company&company_id=999999999')->assertForbidden();

        $this->assertSame($existing->json(), $missing->json());
    }

    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['department'])]
    public function test_unit_filters_do_not_reveal_foreign_organization_identifiers(string $type): void
    {
        $fixture = $this->boundary(true);
        $department = Department::factory()->for(Site::query()->findOrFail($fixture['foreign_scope']->site_id))->create();
        $id = match ($type) {
            'company' => $department->company_id, 'site' => $department->site_id, default => $department->id
        };
        $prefix = $this->scopedUrl('membership-units', $fixture['scope']).'&unit_type=department&unit_'.$type.'_id=';

        $existing = $this->getJson($prefix.$id)->assertUnprocessable();
        $missing = $this->getJson($prefix.'999999999')->assertUnprocessable();

        $this->assertSame($existing->json(), $missing->json());
    }

    public function test_generic_storage_transport_is_not_exposed_before_document_delivery_is_implemented(): void
    {
        $this->getJson('/storage/security-review-fixture')->assertNotFound();
        $this->putJson('/storage/security-review-fixture', [])->assertNotFound();
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    #[TestWith(['POST', 'roles'])]
    #[TestWith(['PATCH', 'roles'])]
    #[TestWith(['POST', 'role-assignments'])]
    #[TestWith(['DELETE', 'role-assignments'])]
    #[TestWith(['POST', 'memberships'])]
    #[TestWith(['PATCH', 'memberships'])]
    #[TestWith(['DELETE', 'memberships'])]
    public function test_every_administration_mutation_requires_csrf(string $method, string $resource): void
    {
        $fixture = $this->boundary(true);
        $this->app['env'] = 'local';
        $id = match ($resource) {
            'roles' => $fixture['role']->id, 'role-assignments' => $fixture['role']->assignments()->sole()->id, default => $fixture['membership']->id
        };
        $url = '/api/v1/'.$resource.($method === 'POST' ? '' : '/'.$id);

        $this->json($method, $url, $this->scopeData($fixture['scope']))->assertStatus(419);

        $this->assertDatabaseCount('rbac_audits', 0);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_authenticated_administrators_cannot_open_the_development_preview_in_other_environments(string $environment): void
    {
        $this->boundary(true);
        $this->app['env'] = $environment;

        $this->getJson('/dev/ui-components')->assertNotFound();
    }

    #[TestWith(['local'])]
    #[TestWith(['testing'])]
    public function test_development_preview_still_requires_authentication(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->getJson('/dev/ui-components')->assertUnauthorized();
    }

    #[TestWith(['inactive'])]
    #[TestWith(['revoked-session'])]
    public function test_inactive_and_revoked_sessions_cannot_probe_existing_or_missing_records(string $state): void
    {
        $fixture = $this->boundary(false);
        $actor = auth()->user();
        $this->grant($actor, $fixture['scope'], ['roles.view']);
        if ($state === 'inactive') {
            $actor->deactivate();
        } else {
            $actor->update(['password' => 'Fictional-review-password-9988']);
        }

        $this->getJson($this->scopedUrl('roles/'.$fixture['role']->id, $fixture['scope']))->assertUnauthorized();
        $this->getJson($this->scopedUrl('roles/999999999', $fixture['scope']))->assertUnauthorized();
    }
}
