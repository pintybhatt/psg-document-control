<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\ManageNumbering;
use App\Models\ConfidentialityClearance;
use App\Models\ControlledDocument;
use App\Models\ControlledDocumentAudit;
use App\Models\ControlledRevision;
use App\Models\ControlledRevisionApproval;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityProfile;
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

class ControlledRevisionApprovalsTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{ControlledDocument, User, Role} */
    private function approvalFixture(): array
    {
        $this->travelTo('2026-10-28 10:00:00');
        [$record, $actor, $role] = $this->fixture('controlled');
        $role->permissions()->syncWithoutDetaching(Permission::query()->where('key', 'controlled.approvals.record')->pluck('id'));

        return [$record, $actor, $role];
    }

    private function approvalUrl(ControlledDocument $record, ?int $revision = null): string
    {
        return $this->detailUrl($record, '/revisions/'.($revision ?? $record->revisions()->whereNull('released_at')->sole()->id).'/approvals');
    }

    private function detailUrl(ControlledDocument $record, string $suffix = ''): string
    {
        return '/api/v1/controlled-documents/'.$record->id.$suffix.'?'.http_build_query([
            'company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id, 'department_id' => $record->profile->department_id,
        ]);
    }

    private function entry(ControlledDocument $record, string $decision): void
    {
        $this->postJson($this->approvalUrl($record), ['lock_version' => $record->fresh()->lock_version, 'decision' => $decision, 'comments' => 'Synthetic '.$decision])->assertOk();
    }

    public function test_rejection_edit_resubmission_and_approval_preserve_revision_specific_evidence(): void
    {
        [$record, $actor] = $this->approvalFixture();
        $revision = $record->revisions()->sole();
        $this->entry($record, 'submitted');
        $this->entry($record, 'rejected');
        $body = $revision->only(['title_en', 'title_lo', 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'revision_reason', 'change_description']);
        $body += ['issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'review_date' => '2027-10-26', 'lock_version' => 3];
        $body['title_en'] = 'Corrected procedure';
        $body['document_code'] = $record->document_code;
        $body['type_id'] = $record->type_id;
        $this->patchJson($this->detailUrl($record), $body)->assertOk();
        $this->entry($record, 'submitted');
        $this->entry($record, 'approved');
        $this->getJson($this->detailUrl($record))->assertOk()->assertJsonCount(4, 'approvals')
            ->assertJsonPath('approvals.0.revision_id', $revision->id)->assertJsonPath('approvals.0.actor_id', $actor->id)
            ->assertJsonPath('approvals.1.decision', 'rejected')->assertJsonPath('approvals.1.snapshot.revision.title_en', 'Test procedure')
            ->assertJsonPath('approvals.2.decision', 'submitted')->assertJsonPath('approvals.3.snapshot.revision.title_en', 'Corrected procedure');
        $this->assertSame(['submitted', 'rejected', 'submitted', 'approved'], ControlledRevisionApproval::query()->orderBy('id')->pluck('decision')->all());
        $this->assertSame($actor->id, ControlledRevisionApproval::query()->first()->actor_id);
        $this->assertSame('2026-10-28 10:00:00', ControlledRevisionApproval::query()->first()->recorded_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('controlled_document_audits', 6);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_attachment_versions_are_snapshotted_and_later_uploads_do_not_change_evidence(): void
    {
        [$record] = $this->approvalFixture();
        $revision = $record->revisions()->sole();
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $revision->id]), ['file' => $this->pdf(), 'reason' => 'Reviewed content'])->assertCreated();
        $this->entry($record, 'approved');
        $snapshot = ControlledRevisionApproval::query()->sole()->snapshot;
        $this->assertCount(1, $snapshot['files']);
        $this->postJson($this->url($record, type: 'controlled', extra: ['revision_id' => $revision->id]), ['file' => $this->pdf('second.pdf'), 'reason' => 'Later content'])->assertCreated();
        $this->assertSame($snapshot, ControlledRevisionApproval::query()->sole()->snapshot);
        $this->entry($record, 'submitted');
        $this->assertCount(2, ControlledRevisionApproval::query()->latest('id')->first()->snapshot['files']);
    }

    public function test_foreign_revision_stale_version_and_forged_fields_cannot_record_entries(): void
    {
        [$record, $actor] = $this->approvalFixture();
        [$foreign] = $this->approvalFixture();
        $this->signIn($actor);
        $this->postJson($this->approvalUrl($record, $foreign->revisions()->sole()->id), ['lock_version' => 1, 'decision' => 'approved'])->assertNotFound();
        $this->entry($record, 'approved');
        $this->postJson($this->approvalUrl($record), ['lock_version' => 1, 'decision' => 'rejected'])->assertUnprocessable()->assertJsonPath('errors.controlled.0', 'controlledStale');
        foreach (['actor_id' => 999, 'revision_id' => 999, 'recorded_at' => '2000-01-01', 'snapshot' => [], 'id' => 1] as $key => $value) {
            $this->postJson($this->approvalUrl($record), ['lock_version' => 2, 'decision' => 'approved', $key => $value])->assertUnprocessable();
        }
        $this->postJson($this->approvalUrl($record), ['lock_version' => 2, 'decision' => 'automatic'])->assertUnprocessable();
        $this->postJson($this->approvalUrl($record), ['lock_version' => 2, 'decision' => 'approved', 'comments' => str_repeat('a', 5001)])->assertUnprocessable();
        $this->assertDatabaseCount('controlled_revision_approvals', 1);
    }

    #[TestWith(['permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['metadata'])]
    public function test_current_permission_membership_and_document_access_are_required(string $boundary): void
    {
        [$record, $actor, $role] = $this->approvalFixture();
        if ($boundary === 'membership') {
            $actor->memberships()->sole()->update(['active' => false]);
        } else {
            $role->permissions()->detach(Permission::query()->where('key', $boundary === 'metadata' ? 'documents.metadata.view' : 'controlled.approvals.record')->value('id'));
        }
        $this->postJson($this->approvalUrl($record), ['lock_version' => 1, 'decision' => 'approved'])->assertForbidden();
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
    }

    public function test_submission_and_decision_permissions_remain_separate(): void
    {
        [$record, $actor, $role] = $this->approvalFixture();
        $role->permissions()->detach(Permission::query()->where('key', 'controlled.drafts.manage')->value('id'));
        $this->postJson($this->approvalUrl($record), ['lock_version' => 1, 'decision' => 'submitted'])->assertForbidden();
        $this->entry($record, 'approved');
        $role->permissions()->detach(Permission::query()->where('key', 'controlled.approvals.record')->value('id'));
        $role->permissions()->syncWithoutDetaching(Permission::query()->where('key', 'controlled.drafts.manage')->pluck('id'));
        $this->postJson($this->approvalUrl($record), ['lock_version' => 2, 'decision' => 'rejected'])->assertForbidden();
        $this->entry($record, 'submitted');
        $this->assertDatabaseCount('controlled_revision_approvals', 2);
    }

    public function test_guests_foreign_sites_departments_and_confidentiality_cannot_expose_history(): void
    {
        [$record] = $this->approvalFixture();
        $this->entry($record, 'rejected');
        [$foreign] = $this->approvalFixture();
        $this->getJson($this->detailUrl($record))->assertForbidden();
        $this->postJson($this->approvalUrl($record), ['lock_version' => 2, 'decision' => 'approved'])->assertForbidden();
        $department = Department::factory()->create(['company_id' => $foreign->profile->company_id, 'site_id' => $foreign->profile->site_id]);
        $this->getJson($this->detailUrl($foreign).'&department_id='.$department->id)->assertForbidden();
        $profile = DocumentSecurityProfile::factory()->atSite(Site::findOrFail($foreign->profile->site_id))->classified(Confidentiality::HighlyConfidential)->create(['resource_type' => 'controlled']);
        $foreign = ControlledDocument::factory()->create(['profile_id' => $profile->id]);
        ControlledRevision::factory()->create(['document_id' => $foreign->id]);
        $this->getJson($this->detailUrl($foreign))->assertNotFound()->assertDontSee('approvals');
        $this->postJson($this->approvalUrl($foreign), ['lock_version' => 1, 'decision' => 'approved'])->assertNotFound();
        $actor = auth()->user();
        ConfidentialityClearance::factory()->forProfile($foreign->profile)->create(['user_id' => $actor->id]);
        DocumentAccessGrant::factory()->create(['profile_id' => $foreign->profile_id, 'user_id' => $actor->id, 'operation' => 'metadata']);
        $this->entry($foreign, 'approved');
        auth()->logout();
        $this->postJson($this->approvalUrl($foreign), ['lock_version' => 2, 'decision' => 'approved'])->assertUnauthorized();
    }

    #[TestWith(['entry'])]
    #[TestWith(['audit'])]
    #[TestWith(['outer'])]
    public function test_failures_and_outer_rollback_remove_entry_audit_and_version_change(string $boundary): void
    {
        [$record, $actor] = $this->approvalFixture();
        if ($boundary === 'outer') {
            DB::beginTransaction();
            $this->entry($record, 'approved');
            DB::rollBack();
        } else {
            $event = 'eloquent.creating: '.($boundary === 'entry' ? ControlledRevisionApproval::class : ControlledDocumentAudit::class);
            Event::listen($event, fn (): bool => false);
            try {
                $this->postJson($this->approvalUrl($record), ['lock_version' => 1, 'decision' => 'approved'])->assertInternalServerError();
            } finally {
                Event::forget($event);
            }
        }
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
        $this->assertDatabaseCount('controlled_document_audits', 1);
        $this->assertSame(1, $record->fresh()->lock_version);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['replace'])]
    public function test_database_protects_approval_history_against_rewrite_and_reparenting(string $operation): void
    {
        [$record] = $this->approvalFixture();
        $this->entry($record, 'approved');
        $this->expectException(QueryException::class);
        if ($operation === 'delete') {
            DB::table('controlled_revision_approvals')->delete();
        } elseif ($operation === 'update') {
            DB::table('controlled_revision_approvals')->update(['revision_id' => 999]);
        } else {
            $row = (array) DB::table('controlled_revision_approvals')->sole();
            $row['comments'] = 'Rewritten';
            $columns = implode(', ', array_keys($row));
            $parameters = implode(', ', array_fill(0, count($row), '?'));
            $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
            DB::statement("{$verb} INTO controlled_revision_approvals ({$columns}) VALUES ({$parameters})", array_values($row));
        }
    }

    public function test_model_history_is_immutable_and_endpoints_do_not_offer_updates(): void
    {
        [$record] = $this->approvalFixture();
        $this->entry($record, 'approved');
        $this->patchJson($this->approvalUrl($record), ['comments' => 'Rewritten'])->assertStatus(405);
        $this->deleteJson($this->approvalUrl($record))->assertStatus(405);
        $this->expectException(ValidationException::class);
        ControlledRevisionApproval::query()->sole()->update(['comments' => 'Rewritten']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_foreign_site_and_company_records_are_hidden_under_an_authorized_context(bool $sameCompany): void
    {
        [$record] = $this->approvalFixture();
        $site = Site::factory()->create($sameCompany ? ['company_id' => $record->profile->company_id] : []);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->create(['resource_type' => 'controlled']);
        $foreign = ControlledDocument::factory()->create(['profile_id' => $profile->id]);
        $revision = ControlledRevision::factory()->create(['document_id' => $foreign->id]);
        $url = str_replace('/'.$record->id.'?', '/'.$foreign->id.'?', $this->detailUrl($record));
        $this->getJson($url)->assertNotFound()->assertDontSee('approvals');
        $url = str_replace('/'.$record->id.'/revisions/', '/'.$foreign->id.'/revisions/', $this->approvalUrl($record, $revision->id));
        $this->postJson($url, ['lock_version' => 1, 'decision' => 'approved'])->assertNotFound();
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
    }

    public function test_approval_posts_require_csrf(): void
    {
        [$record] = $this->approvalFixture();
        $this->app['env'] = 'local';
        $this->postJson($this->approvalUrl($record), ['lock_version' => 1, 'decision' => 'approved'])->assertStatus(419);
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
    }

    public function test_release_is_not_gated_and_old_revision_decisions_cannot_be_reused_for_new_revision(): void
    {
        [$record, $actor] = $this->approvalFixture();
        $this->entry($record, 'rejected');
        $scope = $record->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/APPROVAL', 'padding' => 4, 'active' => true]);
        $registered = $this->postJson($this->detailUrl($record, '/register'), ['lock_version' => 2])->assertOk()->json('data');
        $revision = $record->revisions()->sole();
        $this->postJson($this->detailUrl($record, '/release'), ['lock_version' => $registered['lock_version'], 'revision_id' => $revision->id, 'reason' => 'Legacy release'])->assertOk();
        $this->postJson($this->approvalUrl($record, $revision->id), ['lock_version' => 4, 'decision' => 'approved'])->assertUnprocessable();
        $this->postJson($this->detailUrl($record, '/revisions'), ['lock_version' => 4, 'revision_reason' => 'New revision', 'change_description' => 'New content'])->assertOk();
        $newRevision = $record->revisions()->whereNull('released_at')->sole();
        $this->entry($record, 'submitted');
        $this->assertDatabaseHas('controlled_revision_approvals', ['revision_id' => $newRevision->id, 'decision' => 'submitted']);
        $this->assertSame(0, ControlledRevisionApproval::query()->where('revision_id', $newRevision->id)->where('decision', 'approved')->count());
        $this->assertDatabaseHas('controlled_revision_approvals', ['revision_id' => $revision->id, 'decision' => 'rejected']);
    }
}
