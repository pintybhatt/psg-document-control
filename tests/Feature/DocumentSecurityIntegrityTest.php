<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DocumentSecurityIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['profile'])]
    #[TestWith(['clearance'])]
    public function test_composite_foreign_keys_prevent_company_site_mismatches(string $type): void
    {
        $record = $type === 'profile' ? DocumentSecurityProfile::factory()->create() : ConfidentialityClearance::factory()->create();
        $other = Site::factory()->create();

        $this->expectException(QueryException::class);

        DB::table($record->getTable())->where('id', $record->id)->update(['company_id' => $other->company_id]);
    }

    #[TestWith(['profile'])]
    #[TestWith(['clearance'])]
    public function test_composite_foreign_keys_prevent_department_site_mismatches(string $type): void
    {
        $department = Department::factory()->create();
        $record = $type === 'profile' ? DocumentSecurityProfile::factory()->create() : ConfidentialityClearance::factory()->create();

        $this->expectException(QueryException::class);

        DB::table($record->getTable())->where('id', $record->id)->update(['department_id' => $department->id]);
    }

    public function test_security_descriptor_scope_rejects_mismatched_department_ownership(): void
    {
        $department = Department::factory()->create();
        $site = Site::factory()->create();

        $this->expectException(ValidationException::class);

        new DocumentScope($site->company_id, $site->id, $department->id);
    }

    public function test_unsaved_profile_cannot_create_a_trusted_descriptor(): void
    {
        $profile = DocumentSecurityProfile::factory()->make();

        $this->expectException(ValidationException::class);

        DocumentSecurityDescriptor::fromProfile($profile);
    }

    #[TestWith(['clearance', true])]
    #[TestWith(['clearance', false])]
    #[TestWith(['grant', true])]
    #[TestWith(['grant', false])]
    public function test_database_uniqueness_prevents_duplicate_current_grants_including_inactive_grants(string $type, bool $active): void
    {
        $record = $type === 'clearance' ? ConfidentialityClearance::factory()->create(['active' => $active]) : DocumentAccessGrant::factory()->create(['active' => $active]);
        $attributes = Arr::only($record->getRawOriginal(), ['user_id', 'profile_id', 'company_id', 'site_id', 'department_id', 'classification', 'operation', 'active', 'created_by', 'updated_by']);

        $this->expectException(QueryException::class);

        DB::table($record->getTable())->insert($attributes);
    }

    public function test_highly_confidential_profiles_cannot_disable_allowlist_requirements_in_the_database(): void
    {
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();

        $this->expectException(QueryException::class);

        DB::table('document_security_profiles')->where('id', $profile->id)->update(['restricted' => false]);
    }

    public function test_normal_is_not_a_clearance_classification(): void
    {
        $clearance = ConfidentialityClearance::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('confidentiality_clearances')->where('id', $clearance->id)->update(['classification' => 'normal']);
    }

    #[TestWith(['clearance'])]
    #[TestWith(['grant'])]
    public function test_revocation_metadata_must_be_complete_and_inactive(string $type): void
    {
        $record = $type === 'clearance' ? ConfidentialityClearance::factory()->create() : DocumentAccessGrant::factory()->create();

        $this->expectException(QueryException::class);

        DB::table($record->getTable())->where('id', $record->id)->update(['revoked_at' => now(), 'revoked_by' => $record->created_by]);
    }

    #[TestWith(['clearance'])]
    #[TestWith(['grant'])]
    public function test_revoked_history_cannot_be_reactivated_through_models(string $type): void
    {
        $record = $type === 'clearance' ? ConfidentialityClearance::factory()->create() : DocumentAccessGrant::factory()->create();
        $record->forceFill(['active' => false, 'revoked_at' => now(), 'revoked_by' => $record->created_by])->save();

        $this->expectException(ValidationException::class);

        $record->forceFill(['active' => true, 'revoked_at' => null, 'revoked_by' => null])->save();
    }

    #[TestWith(['classification'])]
    #[TestWith(['restricted'])]
    public function test_profile_cannot_be_declassified_or_have_restrictions_loosened_through_normal_model_writes(string $field): void
    {
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::Confidential)->create(['restricted' => true]);

        $this->expectException(ValidationException::class);

        $profile->update([$field => $field === 'classification' ? Confidentiality::Normal : false]);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_audit_models_protect_history(string $action): void
    {
        $actor = User::factory()->create();
        $audit = DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'event' => 'access.denied', 'reason' => 'unsupported_resource']);

        $this->expectException(ValidationException::class);

        if ($action === 'delete') {
            $audit->delete();
        } else {
            $audit->update(['reason' => 'forged']);
        }
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_database_triggers_protect_audits_when_model_events_are_bypassed(string $action): void
    {
        $actor = User::factory()->create();
        $audit = DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'event' => 'access.denied', 'reason' => 'unsupported_resource']);

        $this->expectException(QueryException::class);

        $query = DB::table('document_security_audits')->where('id', $audit->id);
        if ($action === 'delete') {
            $query->delete();
        } else {
            $query->update(['reason' => 'forged']);
        }
    }
}
