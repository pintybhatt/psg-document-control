<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MembershipIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function attributes(OrganizationMembership $membership): array
    {
        return $membership->only(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id', 'active', 'created_by', 'updated_by']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_database_rejects_duplicate_current_memberships_including_inactive_records(bool $active): void
    {
        $membership = OrganizationMembership::factory()->create(['active' => $active]);

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->insert($this->attributes($membership));
    }

    #[TestWith(['company-site'])]
    #[TestWith(['department-site'])]
    public function test_composite_foreign_keys_reject_mismatched_ownership_when_model_events_are_bypassed(string $mismatch): void
    {
        $department = Department::factory()->create();
        $membership = OrganizationMembership::factory()->inDepartment($department)->create();
        $otherSite = Site::factory()->for($department->company)->create();
        $values = $mismatch === 'company-site'
            ? ['company_id' => Site::factory()->create()->company_id]
            : ['site_id' => $otherSite->id];

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->where('id', $membership->id)->update($values);
    }

    #[TestWith(['company', true, false])]
    #[TestWith(['site', false, false])]
    #[TestWith(['department', true, false])]
    #[TestWith(['site', true, true])]
    public function test_database_rejects_invalid_membership_shapes(string $type, bool $site, bool $department): void
    {
        $unit = Department::factory()->create();
        $actor = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->insert(['user_id' => $actor->id, 'membership_type' => $type, 'company_id' => $unit->company_id,
            'site_id' => $site ? $unit->site_id : null, 'department_id' => $department ? $unit->id : null, 'active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    #[TestWith(['revoked_at'])]
    #[TestWith(['revoked_by'])]
    public function test_database_requires_complete_revocation_metadata_and_inactive_status(string $field): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('organization_memberships')->where('id', $membership->id)->update([$field => $field === 'revoked_at' ? now() : $membership->created_by]);
    }

    public function test_history_remains_distinct_when_a_new_membership_follows_revocation(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $membership->forceFill(['active' => false, 'revoked_by' => $membership->created_by, 'revoked_at' => now()])->save();

        $new = OrganizationMembership::factory()->create($this->attributes($membership) + ['revoked_at' => null, 'revoked_by' => null]);

        $this->assertNotSame($membership->id, $new->id);
        $this->assertNull($membership->refresh()->current_key);
        $this->assertSame('company:'.$new->company_id, $new->refresh()->current_key);
        $this->assertDatabaseCount('organization_memberships', 2);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['membership_type'])]
    #[TestWith(['company_id'])]
    #[TestWith(['created_by'])]
    public function test_membership_identity_is_immutable_on_model_updates(string $field): void
    {
        $membership = OrganizationMembership::factory()->create();
        $value = match ($field) {
            'user_id', 'created_by' => User::factory()->create()->id, 'company_id' => Site::factory()->create()->company_id, default => 'site'
        };

        $this->expectException(ValidationException::class);

        $membership->forceFill([$field => $value])->save();
    }

    #[TestWith(['restore'])]
    #[TestWith(['delete'])]
    public function test_revoked_history_cannot_be_restored_or_deleted_through_models(string $action): void
    {
        $membership = OrganizationMembership::factory()->create();
        $membership->forceFill(['active' => false, 'revoked_by' => $membership->created_by, 'revoked_at' => now()])->save();

        $this->expectException(ValidationException::class);

        if ($action === 'delete') {
            $membership->delete();
        } else {
            $membership->forceFill(['active' => true, 'revoked_at' => null, 'revoked_by' => null])->save();
        }
    }

    public function test_department_cannot_move_sites_while_membership_history_references_it(): void
    {
        $department = Department::factory()->create();
        OrganizationMembership::factory()->inDepartment($department)->create();
        $site = Site::factory()->for($department->company)->create();

        $this->expectException(QueryException::class);

        DB::table('departments')->where('id', $department->id)->update(['site_id' => $site->id]);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_membership_audit_records_are_immutable(string $action): void
    {
        $membership = OrganizationMembership::factory()->create();
        $audit = MembershipAudit::query()->create(['membership_id' => $membership->id, 'actor_id' => $membership->created_by,
            'target_user_id' => $membership->user_id, 'action' => 'assigned', 'after' => $membership->getAttributes()]);

        $this->expectException(ValidationException::class);

        if ($action === 'delete') {
            $audit->delete();
        } else {
            $audit->update(['action' => 'forged']);
        }
    }
}
