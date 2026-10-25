<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_no_roles_and_unknown_or_planned_permissions_are_denied(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $this->signIn($user);

        $this->assertFalse(Gate::forUser($user)->allows('roles.view', $scope));
        $this->assertFalse(app(AccessControl::class)->allows($user, 'invented.bypass', $scope));
        $this->getJson($this->scopedUrl('roles', $scope))->assertForbidden();
        $this->getJson('/api/v1/authorization/contexts')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_multiple_roles_combine_explicit_permissions_without_a_blanket_bypass(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $this->grant($user, $scope, ['roles.view']);
        $this->grant($user, $scope, ['assignments.view']);

        $this->assertTrue(Gate::forUser($user)->allows('roles.view', $scope));
        $this->assertTrue(Gate::forUser($user)->allows('assignments.view', $scope));
        $this->assertFalse(Gate::forUser($user)->allows('roles.manage', $scope));
        $this->assertFalse(Gate::forUser($user)->allows('documents.register', $scope));
        $this->assertFalse(Gate::forUser($user)->allows('roles.view'));
    }

    public function test_global_company_and_site_inheritance_is_explicit_and_never_flows_upward(): void
    {
        $this->definitions();
        $first = Site::factory()->create();
        $sibling = Site::factory()->for($first->company)->create();
        $other = Site::factory()->create();
        $global = AuthorizationScope::context('global')->persist();
        $company = AuthorizationScope::context('company', $first->company_id)->persist();
        $site = AuthorizationScope::context('site', $first->company_id, $first->id)->persist();
        $siblingScope = AuthorizationScope::context('site', $sibling->company_id, $sibling->id)->persist();
        $otherScope = AuthorizationScope::context('site', $other->company_id, $other->id)->persist();
        $groupUser = User::factory()->create();
        $companyUser = User::factory()->create();
        $siteUser = User::factory()->create();
        $this->grant($groupUser, $global, ['roles.view']);
        $this->grant($companyUser, $company, ['roles.view']);
        $this->grant($siteUser, $site, ['roles.view']);
        $access = app(AccessControl::class);

        $this->assertTrue($access->allows($groupUser, 'roles.view', $otherScope));
        $this->assertTrue($access->allows($companyUser, 'roles.view', $site));
        $this->assertTrue($access->allows($companyUser, 'roles.view', $siblingScope));
        $this->assertFalse($access->allows($companyUser, 'roles.view', $otherScope));
        $this->assertFalse($access->allows($companyUser, 'roles.view', $global));
        $this->assertTrue($access->allows($siteUser, 'roles.view', $site));
        $this->assertFalse($access->allows($siteUser, 'roles.view', $siblingScope));
        $this->assertFalse($access->allows($siteUser, 'roles.view', $company));
        $this->assertFalse($access->allows($siteUser, 'roles.view', $global));
    }

    #[TestWith(['user'])]
    #[TestWith(['role'])]
    #[TestWith(['assignment'])]
    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['permission'])]
    public function test_deactivation_or_revocation_is_visible_without_permission_caches(string $record): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        $role = $this->grant($user, $scope, ['roles.view']);
        $access = app(AccessControl::class);
        $this->assertTrue($access->allows($user, 'roles.view', $scope));

        match ($record) {
            'user' => $user->deactivate(),
            'role' => $role->update(['active' => false]),
            'assignment' => $role->assignments()->firstOrFail()->update(['active' => false]),
            'company' => $site->company->update(['active' => false]),
            'site' => $site->update(['active' => false]),
            'permission' => Permission::query()->where('key', 'roles.view')->firstOrFail()->update(['active' => false]),
        };

        $this->assertFalse($access->allows($user, 'roles.view', $scope));
    }

    public function test_permission_revocation_takes_effect_during_an_existing_authenticated_session(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $role = $this->grant($user, $scope, ['roles.view']);
        $this->signIn($user);
        $this->getJson($this->scopedUrl('roles', $scope))->assertOk();

        $role->permissions()->detach();

        $this->getJson($this->scopedUrl('roles', $scope))->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_database_permissions_and_planned_keys_cannot_bypass_the_catalog(): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $role = $this->grant($user, $scope, []);
        $id = DB::table('permissions')->insertGetId(['key' => 'confidential.all', 'area' => 'documents', 'name_en' => 'Forged', 'name_lo' => 'ຕົວຢ່າງ', 'active' => true]);
        $planned = Permission::query()->where('key', 'documents.view')->firstOrFail();
        $planned->update(['active' => true]);
        $role->permissions()->attach([$id, $planned->id]);

        $this->assertFalse(app(AccessControl::class)->allows($user, 'confidential.all', $scope));
        $this->assertFalse(Gate::forUser($user)->allows('documents.view', $scope));
    }

    public function test_site_context_and_user_directory_do_not_leak_sibling_or_other_company_identities(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $sibling = Site::factory()->for($site->company)->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $siblingScope = AuthorizationScope::context('site', $sibling->company_id, $sibling->id)->persist();
        $actor = User::factory()->create();
        $local = User::factory()->create();
        $foreign = User::factory()->create();
        $this->grant($actor, $scope, ['roles.view', 'users.view', 'assignments.view']);
        $this->grant($local, $scope, []);
        $this->grant($foreign, $siblingScope, []);
        $this->signIn($actor);

        $this->getJson('/api/v1/authorization/contexts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.scope_key', $scope->scope_key);
        $this->getJson($this->scopedUrl('role-assignment-users', $scope))->assertOk()->assertJsonMissing(['email' => $foreign->email])->assertJsonFragment(['email' => $local->email]);
        $this->getJson($this->scopedUrl('roles', $siblingScope))->assertForbidden();
    }
}
