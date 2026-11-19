<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class UserOnboardingTest extends TestCase
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

    private function workspaceRole(string $code = 'DOCUMENT_CONTROLLER'): Role
    {
        $role = Role::query()->where('code', $code)->sole();
        app(RbacMutation::class)->run(fn () => $role->permissions()->sync(Permission::query()->whereIn('key', ['organization.access', 'documents.metadata.view', 'incoming.drafts.manage'])->pluck('id')->all()));

        return $role;
    }

    /** @return array<string, mixed> */
    private function account(Site $site, Role $role, ?Department $department = null): array
    {
        return ['name' => 'Synthetic onboarded user', 'email' => 'onboarding@example.test', 'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!',
            'access' => ['company_id' => $site->company_id, 'site_id' => $site->id, 'department_id' => $department?->id, 'role_id' => $role->id]];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_creation_assigns_only_selected_membership_and_site_role_with_a_usable_workspace(bool $departmentAccess): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $other = Site::factory()->create(['company_id' => $site->company_id]);
        $department = $departmentAccess ? Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]) : null;
        $role = $this->workspaceRole($departmentAccess ? 'DEPARTMENT_USER' : 'DOCUMENT_CONTROLLER');
        $id = $this->postJson('/api/v1/user-management', $this->account($site, $role, $department))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $id, 'membership_type' => $departmentAccess ? 'department' : 'site', 'site_id' => $site->id, 'department_id' => $department?->id, 'active' => true]);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseHas('user_role_assignments', ['user_id' => $id, 'role_id' => $role->id, 'scope_id' => AuthorizationScope::context('site', $site->company_id, $site->id)->id, 'active' => true]);
        $this->assertSame(1, User::query()->findOrFail($id)->roleAssignments()->count());
        $this->assertDatabaseHas('membership_audits', ['target_user_id' => $id, 'action' => 'assigned']);
        $this->assertDatabaseHas('rbac_audits', ['subject_type' => 'user', 'subject_id' => $id, 'action' => 'user.created']);
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => 'onboarding@example.test', 'password' => 'SecurePass123!', 'language' => 'en'])->assertRedirect('/dashboard');
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site.id', $site->id)->assertJsonPath('data.0.department.id', $department?->id);
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $site->id)->assertJsonPath('data.0.department_id', $department?->id);
        $unit = ['company_id' => $site->company_id, 'site_id' => $site->id, 'department_id' => $department?->id];
        $this->getJson('/api/v1/incoming-correspondence/lookups?'.http_build_query($unit))->assertOk();
        $this->getJson('/api/v1/incoming-correspondence/lookups?'.http_build_query(array_replace($unit, ['site_id' => $other->id, 'department_id' => null])))->assertForbidden();
        if ($departmentAccess) {
            $this->getJson('/api/v1/incoming-correspondence/lookups?'.http_build_query(array_replace($unit, ['department_id' => null])))->assertForbidden();
            $siblingDepartment = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
            $this->getJson('/api/v1/incoming-correspondence/lookups?'.http_build_query(array_replace($unit, ['department_id' => $siblingDepartment->id])))->assertForbidden();
        }
    }

    #[TestWith(['global', 'company'])]
    #[TestWith(['global', 'site'])]
    #[TestWith(['company', 'company'])]
    #[TestWith(['company', 'site'])]
    #[TestWith(['site', 'site'])]
    public function test_create_and_edit_assign_existing_roles_without_metadata_permission(string $owner, string $assignmentType): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $role = match ($owner) {
            'global' => Role::query()->where('code', 'VIEWER')->sole(),
            'company' => Role::factory()->create(['scope_id' => AuthorizationScope::context('company', $site->company_id)->persist()->id]),
            'site' => Role::factory()->create(['scope_id' => AuthorizationScope::context('site', $site->company_id, $site->id)->persist()->id]),
        };
        $keys = $owner === 'global' ? [] : ['organization.access'];
        if ($keys !== []) {
            app(RbacMutation::class)->run(fn () => $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all()));
        }
        $data = $this->account($site, $role);
        $siteId = $assignmentType === 'site' ? $site->id : null;
        $data['access']['site_id'] = $siteId;
        $scope = AuthorizationScope::context($assignmentType, $site->company_id, $siteId);
        $this->getJson('/api/v1/roles?'.http_build_query($scope->only(['scope_type', 'company_id', 'site_id'])))
            ->assertOk()->assertJsonFragment(['id' => $role->id, 'scope_key' => $role->scope->scope_key, 'permission_keys' => $keys]);

        $createdId = $this->postJson('/api/v1/user-management', $data)->assertCreated()->json('data.id');
        $edited = User::factory()->create();
        $this->patchJson('/api/v1/user-management/'.$edited->id, ['access' => $data['access']])->assertOk();
        $scope = AuthorizationScope::context($assignmentType, $site->company_id, $siteId);

        foreach ([$createdId, $edited->id] as $userId) {
            $this->assertDatabaseHas('organization_memberships', ['user_id' => $userId, 'membership_type' => $assignmentType, 'company_id' => $site->company_id, 'site_id' => $siteId, 'department_id' => null]);
            $this->assertDatabaseHas('user_role_assignments', ['user_id' => $userId, 'role_id' => $role->id, 'scope_id' => $scope->id, 'active' => true]);
            $this->assertSame($keys, app(AccessControl::class)->grantedPermissions(User::query()->findOrFail($userId), $scope));
        }
        $this->assertSame($keys, $role->permissions()->pluck('key')->all());
    }

    #[TestWith(['company'])]
    #[TestWith(['department'])]
    #[TestWith(['role'])]
    #[TestWith(['foreign-company-role'])]
    #[TestWith(['site-role-at-company'])]
    #[TestWith(['department-without-site'])]
    #[TestWith(['inactive-role'])]
    #[TestWith(['unavailable-permission'])]
    #[TestWith(['department-controller'])]
    #[TestWith(['administrator'])]
    #[TestWith(['incomplete'])]
    #[TestWith(['global'])]
    public function test_incompatible_combinations_leave_no_account_or_access(string $invalid): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $foreign = Site::factory()->create();
        $role = $this->workspaceRole();
        $data = $this->account($site, $role);
        if ($invalid === 'company') {
            $data['access']['company_id'] = $foreign->company_id;
        } elseif ($invalid === 'department') {
            $data['access']['department_id'] = Department::factory()->create(['company_id' => $foreign->company_id, 'site_id' => $foreign->id])->id;
        } elseif ($invalid === 'role') {
            $data['access']['role_id'] = Role::factory()->create(['scope_id' => AuthorizationScope::context('site', $foreign->company_id, $foreign->id)->persist()->id])->id;
        } elseif ($invalid === 'foreign-company-role') {
            $data['access']['role_id'] = Role::factory()->create(['scope_id' => AuthorizationScope::context('company', $foreign->company_id)->persist()->id])->id;
        } elseif ($invalid === 'site-role-at-company') {
            $data['access']['site_id'] = null;
            $data['access']['role_id'] = Role::factory()->create(['scope_id' => AuthorizationScope::context('site', $site->company_id, $site->id)->persist()->id])->id;
        } elseif ($invalid === 'department-without-site') {
            $data['access']['site_id'] = null;
            $data['access']['department_id'] = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id])->id;
        } elseif ($invalid === 'inactive-role') {
            $role->update(['active' => false]);
        } elseif ($invalid === 'unavailable-permission') {
            app(RbacMutation::class)->run(fn () => $role->permissions()->attach(Permission::query()->where('key', 'documents.view')->sole()->id));
        } elseif ($invalid === 'department-controller') {
            $data['access']['department_id'] = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id])->id;
        } elseif ($invalid === 'administrator') {
            $data['access']['role_id'] = Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole()->id;
        } elseif ($invalid === 'incomplete') {
            unset($data['access']['site_id']);
        } else {
            $data['access']['scope_type'] = 'global';
        }
        $this->postJson('/api/v1/user-management', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'onboarding@example.test']);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('membership_audits', 0);
        $this->assertDatabaseCount('user_role_assignments', 1);
    }

    #[TestWith(['membership'])]
    #[TestWith(['assignment'])]
    public function test_failure_in_either_access_service_rolls_back_account_and_all_access_history(string $boundary): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $role = $this->workspaceRole();
        $event = 'eloquent.creating: '.($boundary === 'membership' ? MembershipAudit::class : RbacAudit::class);
        Event::listen($event, function (MembershipAudit|RbacAudit $audit) use ($boundary): void {
            if ($boundary === 'membership' || $audit->action === 'assignment.granted') {
                throw new LogicException('Synthetic access audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/user-management', $this->account($site, $role));
            $this->fail('Onboarding survived a failed access audit.');
        } catch (LogicException $exception) {
            $this->assertSame('Synthetic access audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseMissing('users', ['email' => 'onboarding@example.test']);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('membership_audits', 0);
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertDatabaseMissing('rbac_audits', ['action' => 'assignment.granted']);
    }

    public function test_edit_adds_access_and_explicit_revocation_preserves_other_sites_and_identity(): void
    {
        $actor = $this->administrator();
        $site = Site::factory()->create();
        $other = Site::factory()->create(['company_id' => $site->company_id]);
        $role = $this->workspaceRole();
        $id = $this->postJson('/api/v1/user-management', $this->account($site, $role))->assertCreated()->json('data.id');
        $access = $this->account($other, $role)['access'];
        $this->patchJson('/api/v1/user-management/'.$id, ['access' => $access])->assertOk();
        $this->patchJson('/api/v1/user-management/'.$id, ['access' => $access])->assertOk();
        $this->assertSame(2, OrganizationMembership::query()->where('user_id', $id)->count());
        $this->assertSame(2, UserRoleAssignment::query()->where('user_id', $id)->count());
        $detail = $this->getJson('/api/v1/user-management/'.$id.'/access')->assertOk()->assertJsonCount(2, 'memberships')->assertJsonCount(2, 'assignments');
        $this->deleteJson($detail->json('memberships.0.revoke_url'))->assertNoContent();
        $this->deleteJson($detail->json('assignments.0.revoke_url'))->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $id, 'active' => true]);
        $this->assertSame(1, OrganizationMembership::query()->where('user_id', $id)->whereNull('revoked_at')->count());
        $this->assertSame(1, UserRoleAssignment::query()->where('user_id', $id)->where('active', true)->count());
        $this->getJson('/api/v1/user-management/'.$actor->id.'/access')->assertOk()->assertJsonPath('assignments.0.revoke_url', null);
    }

    public function test_unprivileged_users_cannot_onboard_or_read_other_accounts_access(): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $role = $this->workspaceRole();
        $target = User::factory()->create();
        $actor = User::factory()->create();
        $this->grant($actor, AuthorizationScope::context('site', $site->company_id, $site->id), ['users.view', 'users.manage', 'memberships.manage', 'assignments.manage']);
        $this->signIn($actor);
        $this->postJson('/api/v1/user-management', $this->account($site, $role))->assertForbidden();
        $this->patchJson('/api/v1/user-management/'.$target->id, ['access' => $this->account($site, $role)['access']])->assertForbidden();
        $this->getJson('/api/v1/user-management/'.$target->id.'/access')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'onboarding@example.test']);
    }

    public function test_edit_failure_preserves_identity_and_preexisting_access(): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $role = $this->workspaceRole();
        $id = $this->postJson('/api/v1/user-management', $this->account($site, $role))->assertCreated()->json('data.id');
        $other = Site::factory()->create();
        $invalid = $this->account($other, $role)['access'];
        $invalid['role_id'] = Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole()->id;
        $this->patchJson('/api/v1/user-management/'.$id, ['name' => 'Must roll back', 'access' => $invalid])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $id, 'name' => 'Synthetic onboarded user']);
        $this->assertSame(1, OrganizationMembership::query()->where('user_id', $id)->count());
        $this->assertSame(1, UserRoleAssignment::query()->where('user_id', $id)->count());
    }

    public function test_suspended_memberships_are_not_silently_activated_and_revoked_memberships_require_confirmation(): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $role = $this->workspaceRole();
        $data = $this->account($site, $role);
        $id = $this->postJson('/api/v1/user-management', $data)->assertCreated()->json('data.id');
        $membership = OrganizationMembership::query()->where('user_id', $id)->sole();
        $scope = ['scope_type' => 'site', 'company_id' => $site->company_id, 'site_id' => $site->id];
        $url = '/api/v1/memberships/'.$membership->id.'?'.http_build_query($scope);
        $this->patchJson($url, ['active' => false])->assertOk();
        $this->patchJson('/api/v1/user-management/'.$id, ['access' => $data['access']])->assertUnprocessable()->assertJsonValidationErrors('access');
        $this->assertFalse($membership->refresh()->active);
        $this->deleteJson($url)->assertNoContent();
        $this->patchJson('/api/v1/user-management/'.$id, ['access' => $data['access']])->assertUnprocessable()->assertJsonValidationErrors('confirm_regrant');
        $data['access']['confirm_regrant'] = true;
        $this->patchJson('/api/v1/user-management/'.$id, ['access' => $data['access']])->assertOk();
        $this->assertSame(2, OrganizationMembership::query()->where('user_id', $id)->count());
        $this->assertNotNull($membership->refresh()->revoked_at);
        $this->assertSame(1, UserRoleAssignment::query()->where('user_id', $id)->count());
    }

    public function test_self_assignment_remains_forbidden_and_rolls_back_account_edits(): void
    {
        $actor = $this->administrator();
        $site = Site::factory()->create();
        $role = $this->workspaceRole();
        $this->patchJson('/api/v1/user-management/'.$actor->id, ['name' => 'Must not change', 'access' => $this->account($site, $role)['access']])->assertForbidden();
        $this->assertNotSame('Must not change', $actor->refresh()->name);
        $this->assertDatabaseCount('organization_memberships', 0);
    }
}
