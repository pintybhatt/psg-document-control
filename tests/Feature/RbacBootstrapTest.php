<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RbacBootstrapTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_catalog_and_templates_initialize_repeatably_without_users_or_assignments(): void
    {
        $this->artisan('rbac:initialize')->assertExitCode(0);
        $role = Role::query()->where('code', 'VIEWER')->firstOrFail();
        $role->update(['name_en' => 'Configured name', 'active' => false]);

        $this->artisan('rbac:initialize')->assertExitCode(0);

        $this->assertSame('Configured name', $role->refresh()->name_en);
        $this->assertFalse($role->active);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('permissions', 12);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_role_assignments', 0);
        $this->assertSame([], Role::query()->where('code', 'DOCUMENT_CONTROLLER')->firstOrFail()->permissions()->pluck('key')->all());
        $this->assertFalse(Permission::query()->where('key', 'documents.register')->firstOrFail()->active);
        $this->seed();
        $this->assertDatabaseCount('user_role_assignments', 0);
    }

    public function test_global_bootstrap_requires_explicit_confirmation_and_is_idempotent_and_audited(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $options = ['--email' => $user->email, '--scope' => 'global', '--operator' => 'Test operator'];
        $this->artisan('rbac:bootstrap-admin', $options)->assertExitCode(1);
        $this->assertDatabaseCount('user_role_assignments', 0);

        $this->artisan('rbac:bootstrap-admin', $options + ['--confirm-global' => true])->assertExitCode(0);
        $original = UserRoleAssignment::query()->sole()->getAttributes();
        $this->travel(1)->hours();
        $this->artisan('rbac:bootstrap-admin', $options + ['--confirm-global' => true])->assertExitCode(0);

        $this->assertSame($original, UserRoleAssignment::query()->sole()->getAttributes());
        $this->assertDatabaseCount('rbac_audits', 1);
        $audit = RbacAudit::query()->sole();
        $this->assertSame('cli', $audit->source);
        $this->assertSame('Test operator', $audit->after['operator']);
        $this->assertNull($audit->actor_id);
        $this->assertFalse(app(AccessControl::class)->allows($user, 'documents.view', AuthorizationScope::context('global')));
    }

    public function test_site_bootstrap_does_not_grant_company_or_group_access_and_refuses_another_initial_admin(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->create();
        $options = ['--email' => $user->email, '--scope' => 'site', '--company' => $site->company_id, '--site' => $site->id, '--operator' => 'Test operator'];

        $this->artisan('rbac:bootstrap-admin', $options)->assertExitCode(0);
        $this->artisan('rbac:bootstrap-admin', array_replace($options, ['--email' => $other->email]))->assertExitCode(1);

        $access = app(AccessControl::class);
        $this->assertTrue($access->allows($user, 'roles.manage', AuthorizationScope::context('site', $site->company_id, $site->id)));
        $this->assertFalse($access->allows($user, 'roles.manage', AuthorizationScope::context('company', $site->company_id)));
        $this->assertFalse($access->allows($user, 'roles.manage', AuthorizationScope::context('global')));
        $this->assertDatabaseCount('user_role_assignments', 1);
    }

    public function test_bootstrap_requires_an_existing_active_account_and_operator_and_does_not_create_credentials(): void
    {
        $this->definitions();
        $user = User::factory()->inactive()->create();
        $options = ['--email' => $user->email, '--scope' => 'global', '--confirm-global' => true, '--operator' => 'Test operator'];

        $this->artisan('rbac:bootstrap-admin', $options)->assertExitCode(1);
        $this->artisan('rbac:bootstrap-admin', array_replace($options, ['--email' => 'missing@example.test']))->assertExitCode(1);
        unset($options['--operator']);
        $this->artisan('rbac:bootstrap-admin', $options)->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_role_assignments', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['user'])]
    #[TestWith(['role'])]
    #[TestWith(['assignment'])]
    #[TestWith(['permissions'])]
    #[TestWith(['catalog'])]
    public function test_last_effective_security_administrator_cannot_be_removed(string $change): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $role = $this->grant($user, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);

        try {
            match ($change) {
                'user' => $user->deactivate(),
                'role' => $role->update(['active' => false]),
                'assignment' => $role->assignments()->sole()->delete(),
                'permissions' => app(RbacMutation::class)->run(fn () => $role->permissions()->detach()),
                'catalog' => Permission::query()->where('key', 'roles.manage')->firstOrFail()->update(['active' => false]),
            };
            $this->fail('The final administrator was removed.');
        } catch (ValidationException $exception) {
            $this->assertSame(['rbacLastAdministrator'], $exception->errors()['authorization']);
        }

        $this->assertTrue($user->refresh()->active);
        $this->assertTrue($role->refresh()->active);
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertSame([$user->id], app(AccessControl::class)->administrators($scope));
    }

    public function test_a_second_administrator_allows_safe_deactivation_but_not_removing_the_final_one(): void
    {
        $this->definitions();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $this->grant($first, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->grant($second, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);

        $first->deactivate();
        $this->assertFalse($first->refresh()->active);

        $this->expectException(ValidationException::class);
        $second->deactivate();
    }

    public function test_the_final_scoped_administrator_is_protected_without_group_permissions(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        $this->grant($user, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);

        $this->expectException(ValidationException::class);

        $user->deactivate();
    }

    public function test_final_administrator_protection_combines_permissions_from_multiple_roles(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $user = User::factory()->create();
        $firstRole = $this->grant($user, $scope, ['users.view', 'roles.view', 'roles.manage']);
        $this->grant($user, $scope, ['assignments.view', 'assignments.manage']);

        $this->expectException(ValidationException::class);

        $firstRole->update(['active' => false]);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_bootstrap_audit_records_cannot_be_modified_through_models(string $operation): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $this->artisan('rbac:bootstrap-admin', ['--email' => $user->email, '--scope' => 'global', '--operator' => 'Test operator', '--confirm-global' => true])->assertExitCode(0);
        $audit = RbacAudit::query()->sole();

        $this->expectException(ValidationException::class);

        if ($operation === 'update') {
            $audit->update(['action' => 'rewritten']);
        } else {
            $audit->delete();
        }
    }

    public function test_bootstrap_refuses_to_restore_a_revoked_assignment(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $assignment = UserRoleAssignment::query()->create([
            'user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => $scope->id, 'active' => false,
        ]);

        $this->artisan('rbac:bootstrap-admin', [
            '--email' => $user->email, '--scope' => 'global', '--confirm-global' => true, '--operator' => 'Test operator',
        ])->assertExitCode(1);

        $this->assertFalse($assignment->refresh()->active);
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['mismatched-company'])]
    #[TestWith(['inactive-company'])]
    #[TestWith(['inactive-site'])]
    public function test_bootstrap_refuses_invalid_or_inactive_organizational_scopes(string $condition): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $companyId = $site->company_id;
        if ($condition === 'mismatched-company') {
            $companyId = Site::factory()->create()->company_id;
        } elseif ($condition === 'inactive-company') {
            $site->company->update(['active' => false]);
        } else {
            $site->update(['active' => false]);
        }

        $this->artisan('rbac:bootstrap-admin', [
            '--email' => $user->email, '--scope' => 'site', '--company' => $companyId,
            '--site' => $site->id, '--operator' => 'Test operator',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('user_role_assignments', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_reinitialization_preserves_configured_security_administrator_permissions(): void
    {
        $this->definitions();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        app(RbacMutation::class)->run(fn () => $role->permissions()->sync(
            Permission::query()->where('key', 'roles.view')->pluck('id')->all()
        ));

        $this->artisan('rbac:initialize')->assertExitCode(0);

        $this->assertSame(['roles.view'], $role->permissions()->pluck('key')->all());
        $this->assertDatabaseCount('user_role_assignments', 0);
    }
}
