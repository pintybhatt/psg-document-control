<?php

namespace Tests\Feature;

use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageNumbering;
use App\Models\ControlledDocument;
use App\Models\ControlledDocumentAudit;
use App\Models\ControlledDocumentReview;
use App\Models\MasterDataAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ControlledDocumentReviewsTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{ControlledDocument, User, Role} */
    private function reviewFixture(bool $release = true, string $classification = 'normal', ?string $reviewDate = '2026-10-30'): array
    {
        $this->travelTo('2026-10-31 10:00:00');
        [$record, $actor, $role] = $this->fixture('controlled', $classification);
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['controlled.reviews.record', 'master-data.view', 'master-data.manage', 'reports.view'])->pluck('id'));
        if ($release) {
            $record->revisions()->sole()->update(['review_date' => $reviewDate]);
            $scope = $record->profile->documentScope();
            app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/REVIEW/'.$record->register_id, 'padding' => 4, 'active' => true]);
            $manager = app(ManageControlledDocuments::class);
            $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => 1]);
            $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions()->sole()->id, 'reason' => 'Synthetic release']);
        }

        return [$record, $actor, $role];
    }

    private function detailUrl(ControlledDocument $record, string $suffix = ''): string
    {
        return '/api/v1/controlled-documents/'.$record->id.$suffix.'?'.http_build_query(['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
    }

    private function reviewUrl(ControlledDocument $record, ?int $revision = null): string
    {
        return $this->detailUrl($record, '/revisions/'.($revision ?? $record->current_revision_id ?? $record->revisions()->sole()->id).'/reviews');
    }

    private function policyUrl(ControlledDocument $record): string
    {
        return '/api/v1/document-master-data/types/'.$record->type_id.'?'.http_build_query($this->scopeData($record->profile->documentScope()->authorizationScope()));
    }

    private function enablePolicy(ControlledDocument $record): void
    {
        $this->patchJson($this->policyUrl($record), ['review_enabled' => true, 'review_interval_months' => 1, 'review_baseline' => 'completion_date'])->assertOk();
    }

    private function complete(ControlledDocument $record, string $outcome = 'no_changes_required'): void
    {
        $this->postJson($this->reviewUrl($record), ['lock_version' => $record->fresh()->lock_version, 'outcome' => $outcome, 'comments' => 'Synthetic review'])->assertOk();
    }

    private function dashboard(ControlledDocument $record): string
    {
        return '/api/v1/dashboard?'.http_build_query(['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
    }

    public function test_policies_are_disabled_by_default_and_configuration_is_scoped_and_audited(): void
    {
        [$record, $actor] = $this->reviewFixture();
        $this->assertFalse($record->type->review_enabled);
        $this->assertNull($record->type->review_interval_months);
        $this->assertNull($record->type->review_baseline);
        $this->patchJson($this->policyUrl($record), ['review_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('review_enabled');
        $this->enablePolicy($record);
        $audit = MasterDataAudit::query()->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertFalse((bool) $audit->before['review_enabled']);
        $this->assertTrue((bool) $audit->after['review_enabled']);
        $this->assertSame('completion_date', $audit->after['review_baseline']);
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 1);
        [$foreign] = $this->reviewFixture();
        $this->patchJson($this->policyUrl($record), ['review_enabled' => false])->assertForbidden();
        $url = str_replace('/types/'.$foreign->type_id, '/types/'.$record->type_id, $this->policyUrl($foreign));
        $this->patchJson($url, ['review_enabled' => false])->assertNotFound();
        $this->assertTrue($record->type->fresh()->review_enabled);
    }

    #[TestWith(['review_interval_months', 0])]
    #[TestWith(['review_interval_months', -1])]
    #[TestWith(['review_interval_months', 1.5])]
    #[TestWith(['review_interval_months', 1201])]
    #[TestWith(['review_baseline', 'effective_date'])]
    #[TestWith(['review_enabled', 'yes'])]
    public function test_invalid_policy_configuration_is_rejected(string $field, mixed $value): void
    {
        [$record] = $this->reviewFixture();
        $this->patchJson($this->policyUrl($record), [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    public function test_policy_changes_require_master_data_permission_and_audit_failure_rolls_back(): void
    {
        [$record, $actor, $role] = $this->reviewFixture();
        $event = 'eloquent.creating: '.MasterDataAudit::class;
        Event::listen($event, fn (): bool => false);
        try {
            $this->patchJson($this->policyUrl($record), ['review_enabled' => true, 'review_interval_months' => 12, 'review_baseline' => 'completion_date'])->assertInternalServerError();
        } finally {
            Event::forget($event);
        }
        $this->assertFalse($record->type->fresh()->review_enabled);
        $this->assertDatabaseCount('master_data_audits', 0);
        $role->permissions()->detach(Permission::query()->where('key', 'master-data.manage')->value('id'));
        $this->patchJson($this->policyUrl($record), ['review_interval_months' => 12])->assertForbidden();
        $this->assertNull($record->type->fresh()->review_interval_months);
    }

    public function test_reviews_without_a_legacy_date_or_policy_do_not_invent_a_schedule(): void
    {
        [$record] = $this->reviewFixture(true, 'normal', null);
        $this->postJson($this->reviewUrl($record), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertOk();
        $entry = ControlledDocumentReview::query()->sole();
        $this->assertNull($entry->next_review_date);
        $this->assertNull($entry->comments);
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 0)->assertJsonPath('totals.review_soon', 0);
    }

    #[TestWith([12, '2027-10-31'])]
    #[TestWith([36, '2029-10-31'])]
    public function test_explicit_annual_and_multiyear_intervals_are_supported(int $months, string $expected): void
    {
        [$record] = $this->reviewFixture();
        $this->patchJson($this->policyUrl($record), ['review_enabled' => true, 'review_interval_months' => $months, 'review_baseline' => 'completion_date'])->assertOk();
        $this->complete($record);
        $this->assertSame($expected, ControlledDocumentReview::query()->sole()->next_review_date->format('Y-m-d'));
    }

    public function test_disabled_policy_records_identity_timestamp_and_history_without_advancing_existing_date(): void
    {
        [$record, $actor] = $this->reviewFixture();
        $before = $record->currentRevision->getAttributes();
        $this->complete($record);
        $entry = ControlledDocumentReview::query()->sole();
        $this->assertSame($record->current_revision_id, $entry->revision_id);
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('2026-10-31 10:00:00', $entry->recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30', $entry->next_review_date->format('Y-m-d'));
        $this->assertNull($entry->interval_months);
        $this->assertSame($before, $record->currentRevision->fresh()->getAttributes());
        $this->getJson($this->detailUrl($record))->assertOk()->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.comments', 'Synthetic review');
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 1);
        $this->assertDatabaseCount('controlled_revisions', 1);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertDatabaseHas('controlled_document_audits', ['action' => 'review_completed', 'actor_id' => $actor->id, 'revision_id' => $entry->revision_id]);
    }

    public function test_enabled_policy_calculates_from_server_completion_date_without_month_overflow_and_reports_it(): void
    {
        [$record] = $this->reviewFixture();
        $before = $record->currentRevision->getAttributes();
        $this->enablePolicy($record);
        $this->complete($record);
        $entry = ControlledDocumentReview::query()->sole();
        $this->assertSame('2026-11-30', $entry->next_review_date->format('Y-m-d'));
        $this->assertSame(1, $entry->interval_months);
        $this->assertSame('completion_date', $entry->baseline);
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 0)->assertJsonPath('totals.review_soon', 1)->assertJsonPath('reviews.0.review_date', '2026-11-30');
        $this->complete($record, 'revision_required');
        $this->assertSame('2026-11-30', ControlledDocumentReview::query()->latest('id')->first()->next_review_date->format('Y-m-d'));
        $this->assertSame($before, $record->currentRevision->fresh()->getAttributes());
        $this->assertDatabaseCount('controlled_revisions', 1);
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
        $this->assertSame($record->document_code, $record->fresh()->document_code);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->patchJson($this->policyUrl($record), ['review_enabled' => false])->assertOk();
        $this->travelTo('2026-11-01');
        $this->complete($record);
        $this->assertNull(ControlledDocumentReview::query()->latest('id')->first()->interval_months);
        $this->assertSame('2026-11-30', ControlledDocumentReview::query()->latest('id')->first()->next_review_date->format('Y-m-d'));
    }

    public function test_revision_required_does_not_advance_an_overdue_date_or_create_a_revision(): void
    {
        [$record] = $this->reviewFixture();
        $this->enablePolicy($record);
        $this->complete($record, 'revision_required');
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 1);
        $this->assertSame('2026-10-30', ControlledDocumentReview::query()->sole()->next_review_date->format('Y-m-d'));
        $this->assertDatabaseCount('controlled_revisions', 1);
    }

    #[TestWith(['permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['metadata'])]
    public function test_authority_is_rechecked_on_completion(string $boundary): void
    {
        [$record, $actor, $role] = $this->reviewFixture();
        if ($boundary === 'membership') {
            $actor->memberships()->sole()->update(['active' => false]);
        } else {
            $role->permissions()->detach(Permission::query()->where('key', $boundary === 'metadata' ? 'documents.metadata.view' : 'controlled.reviews.record')->value('id'));
        }
        $this->postJson($this->reviewUrl($record), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertForbidden();
        $this->assertDatabaseCount('controlled_document_reviews', 0);
    }

    public function test_only_current_released_revision_can_be_reviewed_and_stale_or_foreign_revisions_are_rejected(): void
    {
        [$record, $actor] = $this->reviewFixture(false);
        $this->postJson($this->reviewUrl($record), ['lock_version' => 1, 'outcome' => 'no_changes_required'])->assertUnprocessable();
        [$record, $actor] = $this->reviewFixture();
        $this->enablePolicy($record);
        $this->complete($record);
        $this->postJson($this->reviewUrl($record), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertUnprocessable();
        $scope = $record->profile->documentScope();
        $manager = app(ManageControlledDocuments::class);
        $record = $manager->revise($actor, $scope, (string) $record->id, ['lock_version' => $record->fresh()->lock_version, 'revision_reason' => 'Review', 'change_description' => 'Synthetic changes']);
        $draft = $record->revisions()->whereNull('released_at')->sole();
        $this->postJson($this->reviewUrl($record, $draft->id), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertUnprocessable();
        $this->postJson($this->reviewUrl($record, 999999), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertNotFound();
        $old = $record->current_revision_id;
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $draft->id, 'reason' => 'Synthetic release']);
        $this->postJson($this->reviewUrl($record, $old), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertUnprocessable();
        $this->getJson($this->detailUrl($record))->assertOk()->assertJsonCount(1, 'reviews')->assertJsonPath('reviews.0.revision_id', $old);
        $this->assertDatabaseCount('controlled_document_reviews', 1);
        $this->getJson($this->dashboard($record))->assertOk()->assertJsonPath('totals.review_overdue', 1);
    }

    #[TestWith(['recorded_at', '2020-01-01'])]
    #[TestWith(['actor_id', 999])]
    #[TestWith(['next_review_date', '2099-01-01'])]
    #[TestWith(['interval_months', 12])]
    #[TestWith(['revision_id', 999])]
    #[TestWith(['outcome', 'approved'])]
    #[TestWith(['comments', 123])]
    public function test_invalid_or_server_controlled_fields_are_rejected(string $field, mixed $value): void
    {
        [$record] = $this->reviewFixture();
        $this->postJson($this->reviewUrl($record), array_replace(['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'], [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('controlled_document_reviews', 0);
    }

    public function test_scope_confidentiality_and_guest_boundaries_protect_completion_and_history(): void
    {
        [$record] = $this->reviewFixture();
        $this->complete($record);
        [$other, $actor] = $this->reviewFixture(true, 'highly_confidential');
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($other->profile->site_id))->create();
        $this->grant($reader, $other->profile->documentScope()->authorizationScope(), ['documents.metadata.view', 'controlled.reviews.record']);
        $this->signIn($reader);
        $this->getJson($this->detailUrl($record))->assertForbidden();
        $this->getJson($this->detailUrl($other))->assertNotFound()->assertDontSee('reviews');
        $this->postJson($this->reviewUrl($other), ['lock_version' => $other->lock_version, 'outcome' => 'no_changes_required'])->assertNotFound();
        auth()->logout();
        $this->postJson($this->reviewUrl($record), ['outcome' => 'no_changes_required'])->assertUnauthorized();
    }

    #[TestWith(['entry'])]
    #[TestWith(['audit'])]
    #[TestWith(['outer'])]
    public function test_review_and_audit_failures_roll_back_history_and_parent_version(string $boundary): void
    {
        [$record] = $this->reviewFixture();
        $count = ControlledDocumentAudit::query()->count();
        if ($boundary === 'outer') {
            DB::beginTransaction();
            $this->complete($record);
            DB::rollBack();
        } else {
            $event = 'eloquent.creating: '.($boundary === 'entry' ? ControlledDocumentReview::class : ControlledDocumentAudit::class);
            Event::listen($event, fn (): bool => false);
            try {
                $this->postJson($this->reviewUrl($record), ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required'])->assertInternalServerError();
            } finally {
                Event::forget($event);
            }
        }
        $this->assertDatabaseCount('controlled_document_reviews', 0);
        $this->assertDatabaseCount('controlled_document_audits', $count);
        $this->assertSame($record->lock_version, $record->fresh()->lock_version);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['replace'])]
    public function test_database_history_is_append_only(string $operation): void
    {
        [$record] = $this->reviewFixture();
        $this->complete($record);
        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table('controlled_document_reviews')->update(['revision_id' => 999]);
        } elseif ($operation === 'delete') {
            DB::table('controlled_document_reviews')->delete();
        } else {
            $row = (array) DB::table('controlled_document_reviews')->sole();
            $row['comments'] = 'Rewrite';
            $columns = implode(', ', array_keys($row));
            $parameters = implode(', ', array_fill(0, count($row), '?'));
            $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
            DB::statement("{$verb} INTO controlled_document_reviews ({$columns}) VALUES ({$parameters})", array_values($row));
        }
    }

    public function test_model_history_cannot_be_modified_and_update_routes_are_absent(): void
    {
        [$record] = $this->reviewFixture();
        $this->complete($record);
        $this->patchJson($this->reviewUrl($record), ['comments' => 'Rewrite'])->assertStatus(405);
        $this->deleteJson($this->reviewUrl($record))->assertStatus(405);
        $this->expectException(ValidationException::class);
        ControlledDocumentReview::query()->sole()->update(['comments' => 'Rewrite']);
    }
}
