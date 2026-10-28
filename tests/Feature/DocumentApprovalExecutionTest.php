<?php

namespace Tests\Feature;

use App\Authorization\DocumentApprovals;
use App\Authorization\ManageApprovalWorkflows;
use App\Authorization\ManageAttachments;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Authorization\ManageOutgoingOperations;
use App\Models\ControlledDocumentAudit;
use App\Models\CorrespondenceApproval;
use App\Models\OrganizationMembership;
use App\Models\OutgoingCorrespondenceAudit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentApprovalExecutionTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function approvalUrl(Model $record): string
    {
        $scope = $record->profile->documentScope();

        return '/api/v1/document-approvals/'.$record->profile->resource_type->value.'/'.$record->id.'?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'department_id' => $scope->departmentId,
            'revision_id' => $record->profile->resource_type->value === 'controlled' ? $record->revisions()->whereNull('released_at')->sole()->id : null]);
    }

    /** @return array{mixed, User, array<int, User>} */
    private function workflow(string $kind, int $levels = 2): array
    {
        [$record, $author, $role] = $this->fixture($kind);
        $scope = $record->profile->documentScope()->authorizationScope();
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['master-data.view', 'master-data.manage', 'roles.view', 'controlled.approvals.record', 'incoming.operations.manage', 'outgoing.operations.manage', 'outgoing.register', 'incoming.register'])->pluck('id'));
        $approvers = [];
        $roleIds = [];
        for ($index = 0; $index < $levels; $index++) {
            $approver = User::factory()->create();
            OrganizationMembership::factory()->for($approver)->atSite(Site::findOrFail($scope->site_id))->create();
            $required = $this->grant($approver, $scope, ['documents.metadata.view', $kind === 'controlled' ? 'controlled.approvals.record' : $kind.'.operations.manage']);
            $approvers[] = $approver;
            $roleIds[] = $required->id;
        }
        app(ManageApprovalWorkflows::class)->save($author, $scope, $kind, ['enabled' => true, 'sequence' => 'sequential', 'role_ids' => $roleIds, 'expected_version' => 0, 'change_reason' => 'Synthetic execution test']);
        $this->signIn($author);

        return [$record, $author, $approvers];
    }

    private function decide(Model $record, User $actor, string $decision, int $status = 200): array
    {
        $this->signIn($actor);
        $state = $this->getJson($this->approvalUrl($record))->assertOk()->json('data');
        $body = ['decision' => $decision, 'content_hash' => $state['content_hash'], 'expected_event_id' => $state['expected_event_id'], 'comments' => 'Synthetic '.$decision];
        if ($record->profile->resource_type->value === 'controlled') {
            $body['lock_version'] = $state['lock_version'];
        }
        $this->postJson($this->approvalUrl($record), $body)->assertStatus($status);

        return $this->getJson($this->approvalUrl($record))->assertOk()->json('data');
    }

    #[TestWith(['controlled', 1])]
    #[TestWith(['controlled', 2])]
    #[TestWith(['controlled', 3])]
    #[TestWith(['incoming', 3])]
    #[TestWith(['outgoing', 3])]
    public function test_required_roles_approve_only_in_sequence_for_the_pinned_configuration(string $kind, int $levels): void
    {
        [$record, $author, $approvers] = $this->workflow($kind, $levels);
        $this->assertSame('pending', $this->decide($record, $author, 'submitted')['status']);
        if ($levels > 1) {
            $this->decide($record, $approvers[1], 'approved', 403);
        }
        foreach ($approvers as $index => $approver) {
            $state = $this->decide($record, $approver, 'approved');
            $this->assertSame($index === $levels - 1 ? 'approved' : 'pending', $state['status']);
        }
        $this->decide($record, $approvers[0], 'approved', 422);
        $this->assertDatabaseCount('document_approval_submissions', 1);
        $this->assertDatabaseCount($kind === 'controlled' ? 'controlled_revision_approvals' : 'correspondence_approvals', $levels + 1);
    }

    #[TestWith(['controlled'])]
    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_rejection_resubmission_and_configuration_edits_preserve_in_progress_requirements(string $kind): void
    {
        [$record, $author, $approvers] = $this->workflow($kind);
        $state = $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        app(ManageApprovalWorkflows::class)->save($author, $record->profile->documentScope()->authorizationScope(), $kind, ['enabled' => false, 'sequence' => 'sequential', 'role_ids' => [], 'expected_version' => 1, 'change_reason' => 'Disable future workflows']);
        $this->signIn($approvers[1]);
        $this->getJson($this->approvalUrl($record))->assertOk()->assertJsonPath('data.configuration_id', $state['configuration_id'])->assertJsonPath('data.current_level', 2);
        $this->assertSame('rejected', $this->decide($record, $approvers[1], 'rejected')['status']);
        $next = $this->decide($record, $author, 'submitted');
        $this->assertNotSame($state['submission_id'], $next['submission_id']);
        $this->assertSame(1, $next['current_level']);
        $this->assertSame($state['configuration_id'], $next['configuration_id']);
        $this->assertDatabaseCount('document_approval_submissions', 2);
    }

    #[TestWith(['controlled'])]
    #[TestWith(['outgoing'])]
    public function test_stale_decisions_self_approval_and_revoked_authority_are_denied(string $kind): void
    {
        [$record, $author, $approvers] = $this->workflow($kind);
        $state = $this->decide($record, $author, 'submitted');
        $scope = $record->profile->documentScope()->authorizationScope();
        $required = Role::findOrFail($state['levels'][0]['role']['id']);
        UserRoleAssignment::query()->create(['user_id' => $author->id, 'role_id' => $required->id, 'scope_id' => $scope->id, 'active' => true]);
        $this->decide($record, $author, 'approved', 403);
        $this->signIn($approvers[0]);
        $stale = ['decision' => 'approved', 'content_hash' => $state['content_hash'], 'expected_event_id' => 0];
        if ($kind === 'controlled') {
            $stale['lock_version'] = $state['lock_version'];
        }
        $this->postJson($this->approvalUrl($record), $stale)->assertUnprocessable();
        $approvers[0]->memberships()->sole()->update(['active' => false]);
        $this->getJson($this->approvalUrl($record))->assertForbidden();
        $this->assertDatabaseCount($kind === 'controlled' ? 'controlled_revision_approvals' : 'correspondence_approvals', 1);
    }

    public function test_controlled_release_requires_approval_of_exact_content_and_preserves_c1_history(): void
    {
        [$record, $author, $approvers] = $this->workflow('controlled', 1);
        $scope = $record->profile->documentScope();
        app(ManageNumbering::class)->save($author, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'EXEC/CD', 'padding' => 4, 'active' => true]);
        $manager = app(ManageControlledDocuments::class);
        $record = $manager->register($author, $scope, (string) $record->id, ['lock_version' => $record->lock_version]);
        $revision = $record->revisions()->sole();
        $release = fn () => $manager->release($author, $scope, (string) $record->id, ['lock_version' => $record->fresh()->lock_version, 'revision_id' => $revision->id, 'reason' => 'Release exact revision']);
        try {
            $release();
            $this->fail('Unapproved release succeeded.');
        } catch (ValidationException $exception) {
            $this->assertSame(['approval' => ['approvalRequired']], $exception->errors());
        }
        $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        $metadata = $revision->only(['title_en', 'title_lo', 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'issue_date', 'effective_date', 'review_date', 'revision_reason', 'change_description']);
        foreach (['issue_date', 'effective_date', 'review_date'] as $key) {
            $metadata[$key] = $revision->{$key}?->format('Y-m-d');
        }
        $metadata['title_en'] = 'Changed approved content';
        $manager->save($author, $scope, $metadata + ['lock_version' => $record->fresh()->lock_version], (string) $record->id);
        $this->signIn($author);
        $this->getJson($this->approvalUrl($record))->assertOk()->assertJsonPath('data.status', 'content_changed');
        try {
            $release();
            $this->fail('Changed content reused approval.');
        } catch (ValidationException $exception) {
            $this->assertSame(['approval' => ['approvalRequired']], $exception->errors());
        }
        $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        $this->assertSame('current', $release()->status);
        $this->assertDatabaseCount('controlled_revision_approvals', 4);
        $this->assertDatabaseCount('correspondence_approvals', 0);
    }

    public function test_outgoing_issuance_rolls_back_until_approved_and_dispatch_requires_reapproval_after_correction(): void
    {
        [$record, $author, $approvers] = $this->workflow('outgoing', 1);
        $scope = $record->profile->documentScope();
        foreach (['internal_register' => 'EXEC/OUT', 'official_outgoing' => 'EXEC/OFFICIAL'] as $purpose => $prefix) {
            app(ManageNumbering::class)->save($author, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => $prefix, 'padding' => 4, 'active' => true]);
        }
        $register = fn () => app(ManageOutgoingCorrespondence::class)->register($author, $scope, (string) $record->id);
        try {
            $register();
            $this->fail('Official issuance bypassed approval.');
        } catch (ValidationException $exception) {
            $this->assertSame(['approval' => ['approvalRequired']], $exception->errors());
        }
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        $record = $register();
        $this->assertSame('EXEC/OUT/2026/0001', $record->internal_number);
        $this->assertSame('EXEC/OFFICIAL/2026/0001', $record->official_number);
        $operations = app(ManageOutgoingOperations::class);
        $record = $operations->act($author, $scope, (string) $record->id, ['action' => 'correct_details', 'revision' => 0, 'details' => ['subject' => 'Corrected before dispatch'], 'note' => 'Audited correction']);
        $dispatch = fn () => $operations->act($author, $scope, (string) $record->id, ['action' => 'dispatch', 'revision' => 1, 'dispatch_date' => '2026-10-28', 'dispatch_channel' => 'email']);
        try {
            $dispatch();
            $this->fail('Corrected content dispatched without approval.');
        } catch (ValidationException $exception) {
            $this->assertSame(['approval' => ['approvalRequired']], $exception->errors());
        }
        $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        $this->assertSame('dispatched', $dispatch()->operation->status);
        $this->assertDatabaseCount('issued_document_numbers', 2);
    }

    #[TestWith(['controlled'])]
    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_attachment_changes_require_resubmission_and_cannot_reuse_completed_approval(string $kind): void
    {
        [$record, $author, $approvers] = $this->workflow($kind, 1);
        $this->decide($record, $author, 'submitted');
        $this->decide($record, $approvers[0], 'approved');
        app(ManageAttachments::class)->upload($author, $record->profile->documentScope(), $kind, (string) $record->id, $kind === 'controlled' ? $record->revisions()->sole()->id : null, $this->pdf(), 'New content');
        $this->signIn($author);
        $this->getJson($this->approvalUrl($record))->assertOk()->assertJsonPath('data.status', 'content_changed')->assertJsonPath('data.can_submit', true);
        $this->decide($record, $approvers[0], 'approved', 422);
        $this->decide($record, $author, 'submitted');
        $this->assertSame('approved', $this->decide($record, $approvers[0], 'approved')['status']);
    }

    #[TestWith(['role'])]
    #[TestWith(['permission'])]
    #[TestWith(['assignment'])]
    public function test_current_role_assignment_and_permission_are_rechecked_for_each_decision(string $boundary): void
    {
        [$record, $author, $approvers] = $this->workflow('outgoing', 1);
        $this->decide($record, $author, 'submitted');
        $assignment = $approvers[0]->roleAssignments()->sole();
        if ($boundary === 'role') {
            $assignment->role->update(['active' => false]);
        } elseif ($boundary === 'permission') {
            $assignment->role->permissions()->detach(Permission::where('key', 'outgoing.operations.manage')->value('id'));
        } else {
            $assignment->update(['active' => false]);
        }
        $this->signIn($approvers[0]);
        if ($boundary === 'permission') {
            $this->getJson($this->approvalUrl($record))->assertOk()->assertJsonPath('data.can_decide', false);
        } else {
            $this->getJson($this->approvalUrl($record))->assertForbidden();
        }
        $this->assertDatabaseCount('correspondence_approvals', 1);
    }

    public function test_foreign_sites_companies_and_confidential_records_do_not_expose_approval_history(): void
    {
        [$record, $author, $approvers] = $this->workflow('outgoing', 1);
        $this->decide($record, $author, 'submitted');
        [$other, $otherAuthor] = $this->fixture('outgoing');
        $this->getJson($this->approvalUrl($record))->assertForbidden();
        $forged = str_replace('/'.$other->id.'?', '/'.$record->id.'?', $this->approvalUrl($other));
        $this->getJson($forged)->assertNotFound()->assertDontSee('history');
        [$restricted] = $this->fixture('outgoing', 'highly_confidential');
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($restricted->profile->site_id))->create();
        $this->grant($reader, $restricted->profile->documentScope()->authorizationScope(), ['documents.metadata.view', 'outgoing.operations.manage']);
        $this->signIn($reader);
        $this->getJson($this->approvalUrl($restricted))->assertNotFound()->assertDontSee('history');
    }

    #[TestWith(['controlled', 'audit'])]
    #[TestWith(['outgoing', 'audit'])]
    #[TestWith(['outgoing', 'event'])]
    #[TestWith(['outgoing', 'outer'])]
    public function test_evidence_audit_failure_and_outer_rollback_leave_no_submission_or_decisions(string $kind, string $boundary): void
    {
        [$record, $author] = $this->workflow($kind, 1);
        $state = $this->getJson($this->approvalUrl($record))->assertOk()->json('data');
        $body = ['decision' => 'submitted', 'content_hash' => $state['content_hash'], 'expected_event_id' => 0];
        if ($kind === 'controlled') {
            $body['lock_version'] = $state['lock_version'];
        }
        if ($boundary === 'outer') {
            DB::beginTransaction();
            app(DocumentApprovals::class)->act($author, $record->profile->documentScope(), $kind, (string) $record->id, null, $body);
            DB::rollBack();
        } else {
            $class = $boundary === 'event' ? CorrespondenceApproval::class : ($kind === 'controlled' ? ControlledDocumentAudit::class : OutgoingCorrespondenceAudit::class);
            $event = 'eloquent.creating: '.$class;
            Event::listen($event, fn (): bool => false);
            try {
                $this->postJson($this->approvalUrl($record), $body)->assertInternalServerError();
            } finally {
                Event::forget($event);
            }
        }
        $this->assertDatabaseCount('document_approval_submissions', 0);
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
        $this->assertDatabaseCount('correspondence_approvals', 0);
    }

    #[TestWith(['document_approval_submissions', 'update'])]
    #[TestWith(['document_approval_submissions', 'delete'])]
    #[TestWith(['document_approval_submissions', 'replace'])]
    #[TestWith(['correspondence_approvals', 'update'])]
    #[TestWith(['correspondence_approvals', 'delete'])]
    #[TestWith(['correspondence_approvals', 'replace'])]
    public function test_database_history_cannot_be_rewritten_deleted_or_replaced(string $table, string $operation): void
    {
        [$record, $author] = $this->workflow('outgoing', 1);
        $this->decide($record, $author, 'submitted');
        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table($table)->update(['id' => 99]);
        } elseif ($operation === 'delete') {
            DB::table($table)->delete();
        } else {
            DB::statement((DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO '.$table.' SELECT * FROM '.$table);
        }
    }
}
