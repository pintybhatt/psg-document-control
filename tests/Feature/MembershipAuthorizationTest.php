<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\ManageMemberships;
use App\Authorization\MembershipAccess;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class MembershipAuthorizationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_membership_without_a_role_never_grants_permissions(): void
    {
        $this->definitions();
        $membership = OrganizationMembership::factory()->create();
        $user = $membership->user;
        $scope = $membership->authorizationScope();
        $this->signIn($user);

        $this->assertTrue(app(MembershipAccess::class)->matches($user, $scope));
        $this->assertFalse(Gate::forUser($user)->allows('organization.access', $scope));
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson($this->scopedUrl('memberships', $scope))->assertForbidden();
    }

    public function test_role_without_membership_is_denied_operational_context_access(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $user = User::factory()->create();
        $this->grant($user, $scope, ['organization.access']);
        $this->signIn($user);

        $this->assertFalse(app(AccessControl::class)->allowsOperational($user, 'organization.access', $scope));
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_company_membership_extends_to_site_workspaces_but_not_site_configuration_or_departments(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $department = Department::factory()->for($site)->create();
        $user = User::factory()->create();
        $company = AuthorizationScope::context('company', $site->company_id);
        $siteScope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->grant($user, $company, ['organization.access', 'reports.view', 'master-data.manage', 'security.audit.view', 'documents.metadata.view']);
        OrganizationMembership::factory()->for($user)->create(['company_id' => $site->company_id]);
        $access = app(AccessControl::class);

        $this->assertTrue($access->allowsOperational($user, 'organization.access', $company));
        $this->assertTrue($access->allowsOperational($user, 'organization.access', $siteScope));
        $this->assertTrue($access->allowsOperational($user, 'reports.view', $siteScope));
        $this->assertFalse($access->allowsOperational($user, 'master-data.manage', $siteScope));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', $siteScope, $department->id));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', AuthorizationScope::context('global')));
        $this->signIn($user);
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.permissions', fn (array $permissions): bool => array_diff(['organization.access', 'reports.view'], $permissions) === []
                && count($permissions) === 2);

        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->assertTrue($access->allowsOperational($user, 'master-data.manage', $siteScope));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', $siteScope, $department->id));
    }

    public function test_site_role_and_membership_cannot_cross_sites_or_companies_or_grant_company_access(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $sibling = Site::factory()->for($site->company)->create();
        $foreign = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $user = User::factory()->create();
        $this->grant($user, $scope, ['organization.access']);
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        OrganizationMembership::factory()->for($user)->atSite($sibling)->create();
        OrganizationMembership::factory()->for($user)->atSite($foreign)->create();
        $access = app(AccessControl::class);

        $this->assertTrue($access->allowsOperational($user, 'organization.access', $scope));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', AuthorizationScope::context('site', $sibling->company_id, $sibling->id)));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', AuthorizationScope::context('site', $foreign->company_id, $foreign->id)));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', AuthorizationScope::context('company', $site->company_id)));
    }

    public function test_department_membership_is_exact_and_multiple_memberships_remain_independent(): void
    {
        $this->definitions();
        $first = Department::factory()->create();
        $second = Department::factory()->for($first->site)->create();
        $foreign = Department::factory()->create();
        $scope = AuthorizationScope::context('site', $first->company_id, $first->site_id);
        $user = User::factory()->create();
        $this->grant($user, $scope, ['organization.access']);
        OrganizationMembership::factory()->for($user)->inDepartment($first)->create();
        $access = app(AccessControl::class);

        $this->assertTrue(Gate::forUser($user)->allows('organization.access', [$scope, $first->id]));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', $scope, $second->id));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', $scope, $foreign->id));
        $this->assertFalse($access->allowsOperational($user, 'organization.access', $scope));

        OrganizationMembership::factory()->for($user)->inDepartment($second)->create();
        $this->assertTrue($access->allowsOperational($user, 'organization.access', $scope, $second->id));
        $this->assertTrue($access->allowsOperational($user, 'organization.access', $scope, $first->id));
    }

    #[TestWith(['user'])]
    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['department'])]
    #[TestWith(['membership'])]
    #[TestWith(['role'])]
    #[TestWith(['assignment'])]
    #[TestWith(['permission'])]
    public function test_inactive_records_immediately_deny_operational_access(string $record): void
    {
        $this->definitions();
        $department = Department::factory()->create();
        $user = User::factory()->create();
        $membership = OrganizationMembership::factory()->for($user)->inDepartment($department)->create();
        $scope = $membership->authorizationScope();
        $role = $this->grant($user, $scope, ['organization.access']);
        $access = app(AccessControl::class);
        $this->assertTrue($access->allowsOperational($user, 'organization.access', $scope, $department->id));

        match ($record) {
            'user' => $user->deactivate(), 'company' => $department->company->update(['active' => false]),
            'site' => $department->site->update(['active' => false]), 'department' => $department->update(['active' => false]),
            'membership' => $membership->update(['active' => false]), 'role' => $role->update(['active' => false]),
            'assignment' => $role->assignments()->sole()->update(['active' => false]),
            'permission' => Permission::query()->where('key', 'organization.access')->firstOrFail()->update(['active' => false]),
        };

        $this->assertFalse($access->allowsOperational($user, 'organization.access', $scope, $department->id));
    }

    public function test_membership_revocation_takes_effect_during_an_existing_session(): void
    {
        $this->definitions();
        $membership = OrganizationMembership::factory()->create();
        $user = $membership->user;
        $this->grant($user, $membership->authorizationScope(), ['organization.access']);
        $actor = User::factory()->create();
        $global = AuthorizationScope::context('global');
        $this->grant($actor, $global, PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS);
        $this->signIn($user);
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertJsonCount(1, 'data');

        app(ManageMemberships::class)->revoke($actor, $global, $membership);

        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertExactJson(['data' => []]);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(app(MembershipAccess::class)->effective($membership));
    }

    public function test_central_security_administration_needs_no_membership_and_never_bypasses_operational_checks(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global');
        $this->grant($user, $scope, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS, 'organization.access']);
        $this->signIn($user);
        $site = Site::factory()->create();

        $this->getJson($this->scopedUrl('roles', $scope))->assertOk();
        $this->getJson($this->scopedUrl('memberships', $scope))->assertOk();
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertExactJson(['data' => []]);
        $this->assertFalse(app(AccessControl::class)->allowsOperational($user, 'organization.access', AuthorizationScope::context('site', $site->company_id, $site->id)));
        $this->assertFalse(app(AccessControl::class)->allowsOperational($user, 'roles.view', $scope));
        $this->assertFalse(Gate::forUser($user)->allows('documents.view', $scope));
        $this->assertDatabaseCount('organization_memberships', 0);
    }
}
