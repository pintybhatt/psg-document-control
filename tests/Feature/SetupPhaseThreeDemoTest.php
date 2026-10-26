<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class SetupPhaseThreeDemoTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private const PERMISSIONS = ['master-data.view', 'master-data.manage', 'numbering.view', 'numbering.manage'];

    private function administrator(): User
    {
        $this->definitions();
        $user = User::factory()->create(['email' => 'admin@dcms.test']);
        $this->grant($user, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
        $this->app['env'] = 'local';

        return $user;
    }

    private function setupDemo(): void
    {
        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator', '--no-interaction' => true])->assertExitCode(0);
    }

    public function test_demo_enables_company_and_site_administration_without_changing_existing_global_grants(): void
    {
        $user = $this->administrator();
        $rolesBefore = Role::query()->with('permissions')->get()->toArray();
        $assignmentsBefore = $user->roleAssignments()->get()->toArray();
        $userBefore = $user->refresh()->getAttributes();

        $this->setupDemo();

        $company = Company::query()->where('code', 'DEMO')->sole();
        $site = $company->sites()->where('code', 'DEMO-SITE')->sole();
        $role = Role::query()->where('code', 'DEMO_PHASE3_ADMINISTRATOR')->sole();
        $this->assertSame(['master-data.manage', 'master-data.view', 'numbering.manage', 'numbering.view'], $role->permissions()->orderBy('key')->pluck('key')->all());
        $this->assertSame($rolesBefore, Role::query()->where('id', '!=', $role->id)->with('permissions')->get()->toArray());
        $this->assertSame($assignmentsBefore, $user->roleAssignments()->where('role_id', '!=', $role->id)->get()->toArray());
        $this->assertSame($userBefore, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseCount('membership_audits', 2);
        $this->assertDatabaseCount('rbac_audits', 4);
        $this->assertDatabaseHas('rbac_audits', ['source' => 'cli', 'action' => 'demo.role.created', 'actor_id' => null]);
        $this->assertSame('Test operator', MembershipAudit::query()->firstOrFail()->after['operator']);

        $this->signIn($user);
        $this->get('/administration')->assertOk();
        foreach ([AuthorizationScope::context('company', $company->id), AuthorizationScope::context('site', $company->id, $site->id)] as $scope) {
            foreach (self::PERMISSIONS as $key) {
                $this->assertTrue(app(AccessControl::class)->allows($user, $key, $scope));
            }
            foreach (['document-master-data/categories', 'document-master-data/types', 'document-master-data/registers', 'document-master-data/classifications', 'numbering-series', 'numbering-series/registers'] as $path) {
                $this->getJson($this->scopedUrl($path, $scope))->assertOk();
            }
            $context = collect($this->getJson('/api/v1/authorization/contexts')->assertOk()->json('data'))->firstWhere('scope_key', $scope->scope_key);
            $this->assertNotNull($context);
            $this->assertSame([], array_values(array_diff(self::PERMISSIONS, $context['permissions'])));
            foreach (['numbering.issue', 'documents.metadata.view', 'documents.content.preview', 'documents.attachments.download', 'clearances.confidential.manage', 'clearances.highly-confidential.manage'] as $key) {
                $this->assertNotContains($key, app(AccessControl::class)->grantedPermissions($user, $scope));
            }
        }
        foreach (['numbering_series', 'numbering_configurations', 'numbering_counters', 'issued_document_numbers', 'document_categories', 'document_types', 'register_configurations', 'document_security_profiles', 'confidentiality_clearances', 'document_access_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_demo_grants_do_not_cross_company_boundaries_or_replace_exact_site_membership(): void
    {
        $user = $this->administrator();
        $foreign = Site::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($foreign)->create();
        $this->setupDemo();
        $company = Company::query()->where('code', 'DEMO')->sole();
        $otherSite = Site::factory()->create(['company_id' => $company->id]);
        $this->signIn($user);

        foreach ([AuthorizationScope::context('site', $foreign->company_id, $foreign->id), AuthorizationScope::context('site', $company->id, $otherSite->id), AuthorizationScope::context('global')] as $scope) {
            foreach (self::PERMISSIONS as $key) {
                $this->assertFalse(app(AccessControl::class)->allows($user, $key, $scope));
            }
            $this->getJson($this->scopedUrl('document-master-data/categories', $scope))->assertForbidden();
            $this->getJson($this->scopedUrl('numbering-series', $scope))->assertForbidden();
        }
    }

    public function test_rerunning_the_fixture_preserves_rows_timestamps_and_audit_counts(): void
    {
        $this->administrator();
        $this->setupDemo();
        $tables = ['companies', 'sites', 'authorization_scopes', 'roles', 'permission_role', 'user_role_assignments', 'organization_memberships', 'rbac_audits', 'membership_audits'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
        }
        $this->travel(1)->hours();

        $this->setupDemo();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all());
        }
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    #[TestWith(['testing'])]
    public function test_fixture_refuses_nonlocal_environments_before_writing(string $environment): void
    {
        $this->administrator();
        $this->app['env'] = $environment;

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('local environment and SQLite')->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_fixture_refuses_non_sqlite_connections_without_connecting_to_them(): void
    {
        $this->administrator();
        DB::setDefaultConnection('mysql');
        try {
            $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('local environment and SQLite')->assertExitCode(1);
        } finally {
            DB::setDefaultConnection('sqlite');
        }

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_fixture_requires_a_named_operator(): void
    {
        $this->administrator();

        $this->artisan('dev:setup-phase3-demo', ['--no-interaction' => true])->expectsOutputToContain('Supply --operator')->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['missing'])]
    #[TestWith(['inactive'])]
    #[TestWith(['unprivileged'])]
    public function test_fixture_does_not_bootstrap_or_elevate_an_unqualified_account(string $condition): void
    {
        $this->definitions();
        $this->app['env'] = 'local';
        if ($condition !== 'missing') {
            User::factory()->create(['email' => 'admin@dcms.test', 'active' => $condition !== 'inactive']);
        }

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('global Security Administrator capabilities')->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('user_role_assignments', 0);
    }

    public function test_fixture_does_not_activate_a_disabled_catalog_permission(): void
    {
        $this->administrator();
        Permission::query()->where('key', 'numbering.manage')->update(['active' => false]);

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('active Phase 3 catalog permissions')->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_existing_company_collision_is_not_overwritten(): void
    {
        $this->administrator();
        $company = Company::factory()->create(['code' => 'DEMO', 'name_en' => 'Existing company']);
        $before = $company->refresh()->getAttributes();

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('company conflicts')->assertExitCode(1);

        $this->assertSame($before, $company->refresh()->getAttributes());
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_existing_role_collision_rolls_back_new_demo_organizations(): void
    {
        $this->administrator();
        Role::factory()->create(['code' => 'DEMO_PHASE3_ADMINISTRATOR', 'scope_id' => AuthorizationScope::context('global')->id]);

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('role conflicts')->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['membership'])]
    #[TestWith(['assignment'])]
    public function test_fixture_does_not_restore_disabled_demo_access(string $kind): void
    {
        $user = $this->administrator();
        $this->setupDemo();
        if ($kind === 'membership') {
            $user->memberships()->firstOrFail()->update(['active' => false]);
        } else {
            $user->roleAssignments()->whereHas('role', fn ($query) => $query->where('code', 'DEMO_PHASE3_ADMINISTRATOR'))->firstOrFail()->update(['active' => false]);
        }
        $audits = RbacAudit::query()->count();

        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->expectsOutputToContain('requires manual review')->assertExitCode(1);

        $this->assertSame($audits, RbacAudit::query()->count());
    }

    #[TestWith([RbacAudit::class])]
    #[TestWith([MembershipAudit::class])]
    public function test_cancelled_audits_roll_back_the_entire_fixture(string $auditClass): void
    {
        $this->administrator();
        $event = 'eloquent.creating: '.$auditClass;
        Event::listen($event, fn (): bool => false);

        try {
            $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->run();
            $this->fail('An unaudited fixture was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cancelled', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
        $this->assertDatabaseCount('membership_audits', 0);
        $this->assertDatabaseMissing('roles', ['code' => 'DEMO_PHASE3_ADMINISTRATOR']);
        $this->assertDatabaseCount('user_role_assignments', 1);
    }
}
