<?php

namespace Tests\Feature;

use App\Authorization\ManageOrganization;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class OrganizationAdministrationTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function administrator(): User
    {
        $this->definitions();
        $actor = User::factory()->create();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->signIn($actor);

        return $actor;
    }

    private function data(string $code = 'EXAMPLE'): array
    {
        return ['code' => $code, 'name_en' => 'Synthetic unit', 'name_lo' => 'ໜ່ວຍງານຕົວຢ່າງ', 'active' => true];
    }

    public function test_global_administrator_can_create_and_edit_structure_with_atomic_audit_history(): void
    {
        $actor = $this->administrator();
        $company = $this->postJson('/api/v1/organization/companies', $this->data())->assertCreated()->json('data.id');
        $site = $this->postJson('/api/v1/organization/sites', $this->data() + ['company_id' => $company])->assertCreated()->json('data.id');
        $department = $this->postJson('/api/v1/organization/departments', $this->data() + ['company_id' => $company, 'site_id' => $site])->assertCreated()->json('data.id');
        foreach (['companies' => $company, 'sites' => $site, 'departments' => $department] as $kind => $id) {
            $this->patchJson('/api/v1/organization/'.$kind.'/'.$id, ['name_lo' => 'ຊື່ໃໝ່'])->assertOk()->assertJsonPath('data.name_lo', 'ຊື່ໃໝ່');
            $this->assertDatabaseHas($kind, ['id' => $id, 'code' => 'EXAMPLE', 'name_lo' => 'ຊື່ໃໝ່']);
            $this->deleteJson('/api/v1/organization/'.$kind.'/'.$id)->assertStatus(405);
        }
        $this->assertDatabaseCount('rbac_audits', 6);
        $this->assertDatabaseHas('rbac_audits', ['actor_id' => $actor->id, 'subject_type' => 'department', 'action' => 'organization.updated', 'scope_id' => AuthorizationScope::context('global')->id]);
        $this->getJson('/api/v1/organization/departments?company_id='.$company.'&site_id='.$site)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/administration/history?scope_type=global&source=organization')->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson('/api/v1/authorization/contexts')->assertOk()->assertJsonPath('can_manage_organization', true);
    }

    #[TestWith(['ordinary'])]
    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['delegated-global'])]
    #[TestWith(['scoped-security-role'])]
    #[TestWith(['revoked'])]
    #[TestWith(['inactive-role'])]
    #[TestWith(['inactive-account'])]
    public function test_structural_endpoints_deny_non_global_or_inactive_authority(string $scenario): void
    {
        $actor = $this->administrator();
        $site = Site::factory()->create();
        if (in_array($scenario, ['revoked', 'inactive-role', 'inactive-account'], true)) {
            $backup = $this->administrator();
            if ($scenario === 'inactive-role') {
                $this->grant($backup, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
            }
            $this->signIn($actor);
            match ($scenario) {
                'revoked' => $actor->roleAssignments()->sole()->update(['active' => false]),
                'inactive-role' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->update(['active' => false]),
                'inactive-account' => $actor->deactivate(),
            };
        } else {
            $actor->roleAssignments()->delete();
            if ($scenario !== 'ordinary') {
                $scope = AuthorizationScope::context($scenario === 'delegated-global' ? 'global' : ($scenario === 'company' ? 'company' : 'site'), $scenario === 'delegated-global' ? null : $site->company_id, in_array($scenario, ['site', 'scoped-security-role'], true) ? $site->id : null)->persist();
                if ($scenario === 'scoped-security-role') {
                    UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->id, 'scope_id' => $scope->id, 'active' => true]);
                } else {
                    $this->grant($actor, $scope, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS]);
                }
            }
        }
        $status = $scenario === 'inactive-account' ? 401 : 403;
        $this->getJson('/api/v1/organization/companies')->assertStatus($status);
        $this->getJson('/api/v1/administration/history?scope_type=global&source=organization')->assertStatus($status);
        $this->postJson('/api/v1/organization/companies', $this->data())->assertStatus($status);
        $this->patchJson('/api/v1/organization/sites/'.$site->id, ['active' => false])->assertStatus($status);
        $this->assertTrue($site->refresh()->active);
        $this->assertDatabaseCount('companies', 1);
        $this->assertSame(0, RbacAudit::query()->where('action', 'like', 'organization.%')->count());
    }

    public function test_guests_cannot_read_or_mutate_structure(): void
    {
        $this->getJson('/api/v1/organization/companies')->assertUnauthorized();
        $this->postJson('/api/v1/organization/companies', $this->data())->assertUnauthorized();
        $this->patchJson('/api/v1/organization/sites/123', ['active' => false])->assertUnauthorized();
    }

    public function test_parent_relationships_duplicate_codes_and_read_filters_are_validated(): void
    {
        $this->administrator();
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $this->postJson('/api/v1/organization/departments', $this->data() + ['company_id' => $other->company_id, 'site_id' => $site->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/organization/sites', $this->data($site->code) + ['company_id' => $site->company_id])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/organization/sites', $this->data($site->code) + ['company_id' => $other->company_id])->assertCreated();
        $this->postJson('/api/v1/organization/companies', ['code' => 'bad code', 'name_en' => '', 'active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/organization/sites?company_id='.$site->company_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $site->id);
        $this->getJson('/api/v1/organization/departments?company_id='.$other->company_id.'&site_id='.$site->id)->assertNotFound();
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_identity_parent_and_audit_fields_cannot_be_changed(): void
    {
        $this->administrator();
        $department = Department::factory()->create();
        $before = $department->refresh()->getAttributes();
        foreach (['code' => 'NEW', 'company_id' => $department->company_id, 'site_id' => $department->site_id, 'actor_id' => 1] as $field => $value) {
            $this->patchJson('/api/v1/organization/departments/'.$department->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/api/v1/organization/sites/'.$department->site_id, ['company_id' => Company::factory()->create()->id])->assertUnprocessable();
        $this->assertSame($before, $department->refresh()->getAttributes());
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['companies'])]
    #[TestWith(['sites'])]
    #[TestWith(['departments'])]
    public function test_empty_identity_and_parent_values_return_422_without_changes(string $kind): void
    {
        $actor = $this->administrator();
        $record = match ($kind) {
            'companies' => Company::factory()->create(), 'sites' => Site::factory()->create(), 'departments' => Department::factory()->create()
        };
        $before = $record->refresh()->getAttributes();

        foreach (['code', 'company_id', 'site_id'] as $field) {
            foreach ([null, '', []] as $value) {
                $this->patchJson('/api/v1/organization/'.$kind.'/'.$record->id, [$field => $value])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        try {
            app(ManageOrganization::class)->save($actor, $kind, ['code' => null], (string) $record->id);
            $this->fail('Empty immutable code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame($before, $record->refresh()->getAttributes());
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    #[TestWith(['companies'])]
    #[TestWith(['sites'])]
    #[TestWith(['departments'])]
    public function test_unused_units_can_be_safely_deactivated_and_reactivated(string $kind): void
    {
        $this->administrator();
        $record = match ($kind) {
            'companies' => Company::factory()->create(), 'sites' => Site::factory()->create(), 'departments' => Department::factory()->create()
        };
        $this->patchJson('/api/v1/organization/'.$kind.'/'.$record->id, ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->patchJson('/api/v1/organization/'.$kind.'/'.$record->id, ['active' => true])->assertOk()->assertJsonPath('data.active', true);
        $this->assertDatabaseCount('rbac_audits', 2);
    }

    public function test_children_and_historical_memberships_prevent_deactivation_without_cascades(): void
    {
        $this->administrator();
        $department = Department::factory()->create();
        $member = OrganizationMembership::factory()->inDepartment($department)->create(['active' => false]);
        foreach (['companies' => $department->company_id, 'sites' => $department->site_id, 'departments' => $department->id] as $kind => $id) {
            $this->patchJson('/api/v1/organization/'.$kind.'/'.$id, ['active' => false])->assertUnprocessable()->assertJsonValidationErrors('active');
            $this->assertDatabaseHas($kind, ['id' => $id, 'active' => true]);
        }
        $this->assertSame($department->id, $member->refresh()->department_id);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_configured_sites_and_their_departments_are_protected_and_inactive_parents_block_activation(): void
    {
        $this->administrator();
        $department = Department::factory()->create();
        AuthorizationScope::context('site', $department->company_id, $department->site_id)->persist();
        $this->patchJson('/api/v1/organization/sites/'.$department->site_id, ['active' => false])->assertUnprocessable();
        $this->patchJson('/api/v1/organization/departments/'.$department->id, ['active' => false])->assertUnprocessable();
        $company = Company::factory()->inactive()->create();
        $this->postJson('/api/v1/organization/sites', $this->data() + ['company_id' => $company->id])->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_cancelled_audit_insert_rolls_back_the_organization_change(): void
    {
        $actor = $this->administrator();
        $event = 'eloquent.creating: '.RbacAudit::class;
        Event::listen($event, fn (): bool => false);
        try {
            app(ManageOrganization::class)->save($actor, 'companies', $this->data());
            $this->fail('Unaudited organization was persisted.');
        } catch (LogicException $exception) {
            $this->assertSame('Organization audit persistence was cancelled.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('rbac_audits', 0);
    }
}
