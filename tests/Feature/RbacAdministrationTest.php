<?php

namespace Tests\Feature;

use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RbacAdministrationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function roleData(AuthorizationScope $scope, array $keys = ['roles.view']): array
    {
        return $this->scopeData($scope) + ['code' => 'EXAMPLE_ROLE', 'name_en' => 'Example role', 'name_lo' => 'ບົດບາດຕົວຢ່າງ', 'description' => 'Example configuration', 'active' => true, 'permission_keys' => $keys];
    }

    public function test_guest_apis_return_401_and_authenticated_users_without_roles_receive_403(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $this->getJson($this->scopedUrl('roles', $scope))->assertUnauthorized();
        $this->postJson('/api/v1/roles', $this->roleData($scope))->assertUnauthorized();
        $this->signIn(User::factory()->create());

        $this->getJson($this->scopedUrl('roles', $scope))->assertForbidden();
        $this->getJson($this->scopedUrl('permissions', $scope))->assertForbidden();
        $this->getJson($this->scopedUrl('role-assignments', $scope))->assertForbidden();
        $this->postJson('/api/v1/roles', $this->roleData($scope))->assertForbidden();
        $this->assertDatabaseCount('roles', 4);
    }

    public function test_authorized_role_creation_updates_and_assignment_revocation_are_scoped_and_audited(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);

        $roleId = $this->postJson('/api/v1/roles', $this->roleData($scope))->assertCreated()->assertJsonPath('data.name_lo', 'ບົດບາດຕົວຢ່າງ')->json('data.id');
        $payload = $this->roleData($scope);
        unset($payload['code']);
        $payload['name_en'] = 'Changed role';
        $this->patchJson('/api/v1/roles/'.$roleId, $payload)->assertOk()->assertJsonPath('data.name_en', 'Changed role');
        $assignmentData = $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $roleId, 'confirm_global' => true];
        $assignmentId = $this->postJson('/api/v1/role-assignments', $assignmentData)->assertCreated()->json('data.id');
        $this->getJson($this->scopedUrl('role-assignments', $scope))->assertOk()->assertJsonFragment(['email' => $target->email]);
        $this->deleteJson('/api/v1/role-assignments/'.$assignmentId, $this->scopeData($scope))->assertNoContent();

        $this->assertFalse(UserRoleAssignment::query()->findOrFail($assignmentId)->active);
        $this->assertDatabaseCount('rbac_audits', 4);
        $this->assertDatabaseHas('rbac_audits', ['actor_id' => $actor->id, 'action' => 'assignment.revoked', 'source' => 'web']);
    }

    public function test_self_assignment_and_self_permission_changes_are_forbidden(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $role = $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $actor->id, 'role_id' => $role->id, 'confirm_global' => true])->assertForbidden();
        $data = $this->roleData($scope, []);
        unset($data['code']);
        $this->patchJson('/api/v1/roles/'.$role->id, $data)->assertForbidden();
        $this->deleteJson('/api/v1/role-assignments/'.$role->assignments()->sole()->id, $this->scopeData($scope))->assertForbidden();
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_delegation_cannot_include_permissions_the_actor_does_not_hold_or_planned_or_unknown_keys(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, ['roles.manage', 'roles.view']);
        $this->signIn($actor);

        $this->postJson('/api/v1/roles', $this->roleData($scope, ['assignments.manage']))->assertForbidden();
        $this->postJson('/api/v1/roles', $this->roleData($scope, ['documents.register']))->assertUnprocessable();
        $this->postJson('/api/v1/roles', $this->roleData($scope, ['confidential.all']))->assertUnprocessable();
        $this->assertDatabaseMissing('roles', ['code' => 'EXAMPLE_ROLE']);
    }

    public function test_forged_company_site_ids_and_cross_scope_role_edits_are_rejected(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $otherScope = AuthorizationScope::context('site', $other->company_id, $other->id)->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);

        $this->getJson($this->scopedUrl('roles', $otherScope))->assertForbidden();
        $this->postJson('/api/v1/roles', array_replace($this->roleData($scope), ['company_id' => $other->company_id]))->assertUnprocessable();
        $this->getJson($this->scopedUrl('roles', $scope))->assertOk()->assertJsonFragment(['code' => 'SECURITY_ADMINISTRATOR', 'editable' => false]);
        $template = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $payload = $this->roleData($scope);
        unset($payload['code']);
        $this->patchJson('/api/v1/roles/'.$template->id, $payload)->assertForbidden();
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_site_administrators_cannot_assign_foreign_users_or_widen_roles_to_global(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $sibling = Site::factory()->for($site->company)->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $siblingScope = AuthorizationScope::context('site', $sibling->company_id, $sibling->id)->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->grant($target, $siblingScope, []);
        $this->signIn($actor);
        $template = Role::query()->where('code', 'VIEWER')->firstOrFail();

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $template->id])->assertForbidden();
        $this->postJson('/api/v1/role-assignments', ['scope_type' => 'global', 'user_id' => $target->id, 'role_id' => $template->id, 'confirm_global' => true])->assertForbidden();
    }

    public function test_global_assignment_requires_a_separate_explicit_confirmation(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);
        $role = Role::query()->where('code', 'VIEWER')->firstOrFail();

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $role->id])->assertUnprocessable()->assertJsonValidationErrors('confirm_global');
    }

    public function test_invalid_assignment_identifiers_and_inactive_targets_are_rejected(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->inactive()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);
        $role = Role::query()->where('code', 'VIEWER')->firstOrFail();

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id + 1, 'role_id' => $role->id, 'confirm_global' => true])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $role->id, 'confirm_global' => true])->assertForbidden();
        $this->assertDatabaseCount('user_role_assignments', 1);
    }

    public function test_read_only_actors_cannot_probe_assignment_target_identifiers_through_validation(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, ['roles.view']);
        $this->signIn($actor);

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $actor->id + 1, 'role_id' => 0, 'confirm_global' => true])->assertForbidden();
    }

    public function test_role_mutations_require_csrf_even_for_an_authorized_browser_session(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);
        $this->app['env'] = 'local';

        $this->postJson('/api/v1/roles', $this->roleData($scope))->assertStatus(419);
        $this->assertDatabaseMissing('roles', ['code' => 'EXAMPLE_ROLE']);
        $this->withSession(['_token' => 'known-rbac-token']);
        $this->postJson('/api/v1/roles', $this->roleData($scope) + ['_token' => 'known-rbac-token'])->assertCreated();
    }

    public function test_role_assignment_cannot_delegate_permissions_the_actor_does_not_hold(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, ['users.view', 'roles.view', 'assignments.manage']);
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $this->signIn($actor);

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + [
            'user_id' => $target->id, 'role_id' => $role->id, 'confirm_global' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $target->id]);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_revocation_cannot_target_an_assignment_at_a_sibling_site(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $sibling = Site::factory()->for($site->company)->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $siblingScope = AuthorizationScope::context('site', $sibling->company_id, $sibling->id)->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $role = $this->grant($target, $siblingScope, []);
        $assignment = $role->assignments()->sole();
        $this->signIn($actor);

        $this->getJson($this->scopedUrl('role-assignments', $scope))->assertOk()->assertJsonMissing(['email' => $target->email]);
        $this->deleteJson('/api/v1/role-assignments/'.$assignment->id, $this->scopeData($scope))->assertForbidden();

        $this->assertTrue($assignment->refresh()->active);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_inactive_roles_cannot_be_assigned_even_by_a_security_administrator(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $role = Role::query()->where('code', 'VIEWER')->firstOrFail();
        $role->update(['active' => false]);
        $this->signIn($actor);

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + [
            'user_id' => $target->id, 'role_id' => $role->id, 'confirm_global' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $target->id]);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_role_resource_edit_controls_use_the_validated_administration_scope(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->signIn($actor);

        $roleId = $this->postJson('/api/v1/roles', $this->roleData($scope))->assertCreated()->assertJsonPath('data.editable', true)->json('data.id');
        $this->getJson($this->scopedUrl('roles/'.$roleId, $scope))->assertOk()->assertJsonPath('data.editable', true);
        $this->getJson($this->scopedUrl('roles', $scope))->assertOk()->assertJsonFragment(['code' => 'EXAMPLE_ROLE', 'editable' => true]);
    }
}
