<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class MembershipAdministrationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function administrator(AuthorizationScope $scope): User
    {
        $actor = User::factory()->create();
        $this->grant($actor, $scope, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS]);
        $this->signIn($actor);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function payload(AuthorizationScope $scope, User $target, Site $site): array
    {
        return $this->scopeData($scope) + ['user_id' => $target->id, 'unit_type' => 'site', 'unit_company_id' => $site->company_id, 'unit_site_id' => $site->id, 'unit_department_id' => null];
    }

    public function test_membership_apis_return_401_for_guests_and_403_without_administrative_permissions(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $membership = OrganizationMembership::factory()->create();
        $this->getJson($this->scopedUrl('memberships', $scope))->assertUnauthorized();
        $this->postJson('/api/v1/memberships', $this->scopeData($scope))->assertUnauthorized();
        $this->patchJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope) + ['active' => false])->assertUnauthorized();
        $this->deleteJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope))->assertUnauthorized();
        $this->signIn(User::factory()->create());

        $this->getJson($this->scopedUrl('memberships', $scope))->assertForbidden();
        $this->getJson($this->scopedUrl('membership-units', $scope).'&unit_type=site')->assertForbidden();
        $this->getJson($this->scopedUrl('membership-users', $scope))->assertForbidden();
        $this->postJson('/api/v1/memberships', $this->scopeData($scope) + ['user_id' => 0])->assertForbidden();
        $this->patchJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope) + ['active' => false])->assertForbidden();
        $this->deleteJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope))->assertForbidden();
        $this->assertTrue($membership->refresh()->active);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    public function test_central_administrator_can_assign_update_and_revoke_membership_with_correct_audit_identity(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $actor = $this->administrator($scope);
        $target = User::factory()->create();
        $department = Department::factory()->create();
        $data = array_replace($this->payload($scope, $target, $department->site), ['unit_type' => 'department', 'unit_department_id' => $department->id]);

        $id = $this->postJson('/api/v1/memberships', $data)->assertCreated()->assertJsonPath('data.effective', true)
            ->assertJsonPath('data.department.name_lo', $department->name_lo)->json('data.id');
        $this->patchJson('/api/v1/memberships/'.$id, $this->scopeData($scope) + ['active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchJson('/api/v1/memberships/'.$id, $this->scopeData($scope) + ['active' => true])->assertOk()->assertJsonPath('data.effective', true);
        $this->deleteJson('/api/v1/memberships/'.$id, $this->scopeData($scope))->assertNoContent();

        $membership = OrganizationMembership::query()->findOrFail($id);
        $this->assertFalse($membership->active);
        $this->assertSame($actor->id, $membership->created_by);
        $this->assertSame($actor->id, $membership->updated_by);
        $this->assertSame($actor->id, $membership->revoked_by);
        $this->assertNotNull($membership->revoked_at);
        $audits = MembershipAudit::query()->orderBy('id')->get();
        $this->assertSame(['assigned', 'updated', 'updated', 'revoked'], $audits->pluck('action')->all());
        $this->assertSame([$actor->id], $audits->pluck('actor_id')->unique()->values()->all());
        $this->assertSame([$target->id], $audits->pluck('target_user_id')->unique()->values()->all());
        $this->assertSame($department->id, $audits->last()->after['department_id']);
        $this->assertTrue((bool) $audits->last()->before['active']);
        $this->assertFalse((bool) $audits->last()->after['active']);
        $this->getJson($this->scopedUrl('memberships', $scope))->assertOk()->assertJsonPath('data.0.status', 'revoked');
    }

    public function test_site_admin_cannot_assign_in_a_sibling_site_or_change_a_foreign_membership(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $sibling = Site::factory()->for($site->company)->create();
        $foreign = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->administrator($scope);
        $target = User::factory()->create();
        $this->grant($target, $scope, []);
        $otherMembership = OrganizationMembership::factory()->atSite($foreign)->create();

        $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $sibling))->assertUnprocessable()->assertJsonValidationErrors('unit_site_id');
        $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $foreign))->assertUnprocessable()->assertJsonValidationErrors('unit_company_id');
        $this->postJson('/api/v1/memberships', $this->payload(AuthorizationScope::context('global'), $target, $site))->assertForbidden();
        $this->patchJson('/api/v1/memberships/'.$otherMembership->id, $this->scopeData($scope) + ['active' => false])->assertNotFound();
        $this->deleteJson('/api/v1/memberships/'.$otherMembership->id, $this->scopeData($scope))->assertNotFound();
        $this->getJson($this->scopedUrl('memberships', $scope))->assertOk()->assertJsonCount(0, 'data');
        $this->assertTrue($otherMembership->refresh()->active);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    public function test_company_and_site_unit_directories_never_expose_unrelated_or_inactive_units(): void
    {
        $this->definitions();
        $local = Department::factory()->create();
        $sibling = Department::factory()->for(Site::factory()->for($local->company))->create();
        Department::factory()->create();
        Department::factory()->for($local->site)->inactive()->create();
        $scope = AuthorizationScope::context('site', $local->company_id, $local->site_id);
        $this->administrator($scope);

        $this->getJson($this->scopedUrl('membership-units', $scope).'&unit_type=department')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $local->id);
        $this->getJson($this->scopedUrl('membership-units', $scope).'&unit_type=company')->assertOk()->assertJsonCount(0, 'data');
        $company = AuthorizationScope::context('company', $local->company_id);
        $this->administrator($company);
        $this->getJson($this->scopedUrl('membership-units', $company).'&unit_type=department')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $sibling->id]);
    }

    public function test_site_admin_eligible_accounts_include_real_members_but_exclude_unrelated_accounts(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->administrator($scope);
        $member = OrganizationMembership::factory()->atSite($site)->create();
        $unrelated = User::factory()->create();
        $sibling = OrganizationMembership::factory()->atSite(Site::factory()->for($site->company)->create())->create();

        $this->getJson($this->scopedUrl('membership-users', $scope))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonFragment(['email' => $member->user->email])->assertJsonMissing(['email' => $unrelated->email])->assertJsonMissing(['email' => $sibling->user->email]);
        $this->getJson($this->scopedUrl('role-assignment-users', $scope))->assertOk()->assertJsonFragment(['email' => $member->user->email]);
        $this->postJson('/api/v1/memberships', $this->payload($scope, $unrelated, $site))->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_self_assignment_updates_and_revocation_are_forbidden(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $actor = $this->administrator($scope);
        $site = Site::factory()->create();
        $membership = OrganizationMembership::factory()->for($actor)->atSite($site)->create();

        $this->postJson('/api/v1/memberships', $this->payload($scope, $actor, $site))->assertForbidden();
        $this->patchJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope) + ['active' => false])->assertForbidden();
        $this->deleteJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope))->assertForbidden();
        $this->assertTrue($membership->refresh()->active);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    public function test_revoked_membership_is_terminal_and_regrant_requires_explicit_new_authorization(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $site = Site::factory()->create();
        $target = User::factory()->create();
        $data = $this->payload($scope, $target, $site);
        $id = $this->postJson('/api/v1/memberships', $data)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/memberships', $data)->assertUnprocessable()->assertJsonValidationErrors(['membership' => 'membershipDuplicate']);
        $this->deleteJson('/api/v1/memberships/'.$id, $this->scopeData($scope))->assertNoContent();

        $this->patchJson('/api/v1/memberships/'.$id, $this->scopeData($scope) + ['active' => true])->assertUnprocessable()->assertJsonValidationErrors(['membership' => 'membershipImmutable']);
        $this->postJson('/api/v1/memberships', $data)->assertUnprocessable()->assertJsonValidationErrors(['confirm_regrant' => 'membershipConfirmRegrant']);
        $newId = $this->postJson('/api/v1/memberships', $data + ['confirm_regrant' => true])->assertCreated()->json('data.id');

        $this->assertNotSame($id, $newId);
        $this->assertNotNull(OrganizationMembership::query()->findOrFail($id)->revoked_at);
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseCount('membership_audits', 3);
    }

    #[TestWith(['created_by'])]
    #[TestWith(['updated_by'])]
    #[TestWith(['revoked_by'])]
    #[TestWith(['actor_id'])]
    public function test_clients_cannot_forge_membership_audit_identity(string $field): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $site = Site::factory()->create();
        $target = User::factory()->create();

        $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $site) + [$field => $target->id])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    public function test_department_shape_mismatch_and_inactive_units_are_rejected_without_audit_side_effects(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $target = User::factory()->create();
        $site = Site::factory()->create();
        $department = Department::factory()->create();
        $data = $this->payload($scope, $target, $site);

        $this->postJson('/api/v1/memberships', array_replace($data, ['unit_type' => 'department', 'unit_department_id' => $department->id]))->assertUnprocessable();
        $site->update(['active' => false]);
        $this->postJson('/api/v1/memberships', $data)->assertForbidden();
        $this->assertDatabaseCount('membership_audits', 0);
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_initializer_requires_confirmation_for_stored_grants_and_global_system_authority_needs_no_membership(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();
        $oldKeys = $role->permissions()->pluck('key')->sort()->values()->all();
        $this->artisan('rbac:initialize', ['--enable-membership-administration' => true])->assertExitCode(1);
        $this->assertSame($oldKeys, $role->permissions()->pluck('key')->sort()->values()->all());
        $options = ['--enable-membership-administration' => true, '--operator' => 'Test operator', '--confirm-global' => true];

        $this->artisan('rbac:initialize', $options)->assertExitCode(0);
        $this->artisan('rbac:initialize', $options)->assertExitCode(0);
        $this->artisan('rbac:bootstrap-admin', ['--email' => $user->email, '--scope' => 'global', '--operator' => 'Test operator', '--confirm-global' => true])->assertExitCode(0);

        $this->assertSame(['role.membership-administration-enabled', 'administrator.bootstrapped'], RbacAudit::query()->orderBy('id')->pluck('action')->all());
        $this->assertTrue(app(AccessControl::class)->allows($user, 'memberships.manage', AuthorizationScope::context('global')));
        $this->assertTrue(app(AccessControl::class)->isSystemAdministrator($user));
        $this->assertTrue(app(AccessControl::class)->allowsOperational($user, 'organization.access', AuthorizationScope::context('global')));
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_membership_mutations_require_csrf_for_authenticated_administrators(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $site = Site::factory()->create();
        $target = User::factory()->create();
        $this->app['env'] = 'local';

        $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $site))->assertStatus(419);
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_global_admin_can_revoke_inactive_unit_memberships_without_reactivating_them(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $site = Site::factory()->create();
        $membership = OrganizationMembership::factory()->atSite($site)->inactive()->create();
        $site->update(['active' => false]);

        $this->getJson($this->scopedUrl('memberships', $scope))->assertOk()->assertJsonPath('data.0.effective', false)
            ->assertJsonPath('data.0.manageable', true)->assertJsonPath('data.0.activatable', false);
        $this->patchJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope) + ['active' => true])->assertForbidden();
        $this->deleteJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope))->assertNoContent();

        $this->assertNotNull($membership->refresh()->revoked_at);
        $this->assertDatabaseCount('membership_audits', 1);
    }

    public function test_membership_management_without_identity_visibility_cannot_discover_or_assign_users(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $actor = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS);
        $this->signIn($actor);
        $target = User::factory()->create();
        $site = Site::factory()->create();

        $this->getJson($this->scopedUrl('membership-users', $scope))->assertForbidden();
        $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $site))->assertForbidden();

        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_update_cannot_move_membership_or_change_the_target_identity(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $actor = $this->administrator($scope);
        $membership = OrganizationMembership::factory()->create();

        $this->patchJson('/api/v1/memberships/'.$membership->id, $this->scopeData($scope) + [
            'active' => false, 'user_id' => $actor->id, 'unit_type' => 'site', 'unit_site_id' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'unit_type', 'unit_site_id']);

        $this->assertTrue($membership->refresh()->active);
        $this->assertDatabaseCount('membership_audits', 0);
    }

    public function test_existing_security_role_grants_are_preserved_on_normal_catalog_initialization(): void
    {
        $this->definitions();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail();

        $this->artisan('rbac:initialize')->assertExitCode(0);

        $this->assertSame([], array_intersect(PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS, $role->permissions()->pluck('key')->all()));
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_failed_audit_write_rolls_back_the_membership_grant(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $this->administrator($scope);
        $site = Site::factory()->create();
        $target = User::factory()->create();
        Exceptions::fake();
        $event = 'eloquent.creating: '.MembershipAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated audit failure');
        });

        try {
            $this->postJson('/api/v1/memberships', $this->payload($scope, $target, $site))->assertInternalServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('membership_audits', 0);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_context_permission_rollout_requires_review_and_does_not_create_memberships_or_security_permissions(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global');
        $user = User::factory()->create();
        $role = $this->grant($user, $scope, []);
        $options = ['--organization-context-role' => $role->code];
        $this->artisan('rbac:initialize', $options)->assertExitCode(1);
        $this->assertSame([], $role->permissions()->pluck('key')->all());
        $options += ['--operator' => 'Test operator', '--confirm-global' => true];

        $this->artisan('rbac:initialize', $options)->assertExitCode(0);
        $this->artisan('rbac:initialize', $options)->assertExitCode(0);

        $this->assertSame(['organization.access'], $role->permissions()->pluck('key')->all());
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('user_role_assignments', 1);
        $this->assertDatabaseCount('rbac_audits', 1);
        $this->assertSame('role.organization-context-enabled', RbacAudit::query()->sole()->action);
        $this->assertFalse(app(AccessControl::class)->allowsOperational($user, 'organization.access', $scope));
        $this->assertFalse(app(AccessControl::class)->allows($user, 'roles.manage', $scope));
    }
}
