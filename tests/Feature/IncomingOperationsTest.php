<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceOperation;
use App\Models\IncomingOperationAudit;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class IncomingOperationsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, IncomingCorrespondence, Role} */
    private function fixture(): array
    {
        $this->travelTo('2026-10-26 12:00:00');
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['incoming.drafts.manage', 'incoming.register', 'numbering.manage', 'numbering.issue', 'documents.metadata.view', 'incoming.operations.manage', 'incoming.operations.reopen']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'OPS/IN', 'padding' => 6, 'active' => true]);
        $draft = app(ManageIncomingCorrespondence::class)->save($actor, new DocumentScope($scope->company_id, $scope->site_id), [
            'type_id' => $type->id, 'subject' => '=Synthetic subject', 'sender_organization' => 'Example sender',
            'correspondence_date' => today()->toDateString(), 'received_date' => today()->toDateString(),
            'classification' => 'normal', 'priority' => 'normal', 'channel' => 'courier',
        ]);
        $record = app(ManageIncomingCorrespondence::class)->register($actor, new DocumentScope($scope->company_id, $scope->site_id), (string) $draft->id);
        $this->signIn($actor);

        return [$actor, $scope, $record, $role];
    }

    private function url(AuthorizationScope $scope, IncomingCorrespondence $record, string $suffix = '/operations'): string
    {
        return '/api/v1/incoming-correspondence/'.$record->id.$suffix.'?'.http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
    }

    public function test_assignment_reassignment_progress_and_history_preserve_registered_identity(): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $identity = $record->getRawOriginal();
        $issuance = DB::table('issued_document_numbers')->first();
        $department = Department::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        OrganizationMembership::factory()->for($actor)->inDepartment($department)->create();
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0, 'responsible_user_id' => $actor->id, 'due_date' => today()->subDay()->toDateString()])->assertOk()->assertJsonPath('data.operations.status', 'assigned')->assertJsonPath('data.due_indicator', 'overdue');
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 1, 'responsible_user_id' => null, 'responsible_department_id' => $department->id, 'status' => 'in_progress', 'due_date' => today()->toDateString()])->assertOk()->assertJsonPath('data.operations.responsible_user_id', null)->assertJsonPath('data.due_indicator', 'today');
        $this->postJson($this->url($scope, $record), ['action' => 'note', 'revision' => 2, 'note' => 'Follow-up with receiving department'])->assertOk();
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonPath('history.0.before.responsible_user_id', null)->assertJsonPath('history.1.before.responsible_user_id', $actor->name)->assertJsonPath('history.2.note', 'Follow-up with receiving department');
        $this->assertSame($identity, $record->fresh()->getRawOriginal());
        $this->assertEquals($issuance, DB::table('issued_document_numbers')->first());
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertDatabaseHas('incoming_operation_audits', ['actor_id' => $actor->id, 'revision' => 3, 'action' => 'noted']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_closure_allows_optional_response_paths_and_reopening_requires_a_retained_reason(bool $response): void
    {
        [$actor, $scope, $record, $role] = $this->fixture();
        $revision = 0;
        if ($response) {
            $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => $revision++, 'response_required' => true])->assertOk();
            $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => $revision])->assertUnprocessable()->assertJsonPath('errors.operation.0', 'incomingResponsePending');
            $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => $revision++, 'response_completed' => true])->assertOk()->assertJsonPath('data.operations.status', 'responded')->assertJsonPath('data.operations.response_completed_by', $actor->id);
        }
        $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => $revision++, 'note' => 'Handling complete'])->assertOk()->assertJsonPath('data.operations.status', 'closed')->assertJsonPath('data.operations.closure_remarks', 'Handling complete')->assertJsonPath('data.due_indicator', null);
        $this->postJson($this->url($scope, $record), ['action' => 'note', 'revision' => $revision, 'note' => 'Cannot change closed item'])->assertUnprocessable();
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('note');
        $permission = Permission::query()->where('key', 'incoming.operations.reopen')->value('id');
        $role->permissions()->detach($permission);
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision, 'note' => 'Additional handling'])->assertForbidden();
        $role->permissions()->attach($permission);
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision, 'note' => 'Additional handling'])->assertOk()->assertJsonPath('data.operations.closed_at', null)->assertJsonPath('data.operations.status', $response ? 'responded' : 'received');
        $this->assertDatabaseHas('incoming_operation_audits', ['action' => 'reopened', 'note' => 'Additional handling']);
    }

    public function test_stale_updates_and_unexplained_response_corrections_do_not_overwrite_current_state(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0, 'response_required' => true, 'response_completed' => true])->assertOk();
        $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => 0])->assertConflict();
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 1, 'response_completed' => false])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 1, 'response_completed' => false, 'note' => 'Response recorded against wrong item'])->assertOk()->assertJsonPath('data.operations.status', 'in_progress')->assertJsonPath('data.operations.response_completed_at', null);
        $this->assertDatabaseCount('incoming_operation_audits', 2);
    }

    #[TestWith(['permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['confidentiality'])]
    #[TestWith(['scope'])]
    public function test_operational_updates_enforce_authority_and_exact_document_boundaries(string $boundary): void
    {
        [$actor, $scope, $record, $role] = $this->fixture();
        if ($boundary === 'permission') {
            $role->permissions()->detach(Permission::query()->where('key', 'incoming.operations.manage')->value('id'));
        } elseif ($boundary === 'membership') {
            OrganizationMembership::query()->update(['active' => false]);
        } elseif ($boundary === 'confidentiality') {
            DB::table('document_security_profiles')->where('id', $record->profile_id)->update(['restricted' => true]);
        } else {
            $foreign = Site::factory()->create();
            $scope = AuthorizationScope::context('site', $foreign->company_id, $foreign->id)->persist();
            OrganizationMembership::factory()->for($actor)->atSite($foreign)->create();
            $this->grant($actor, $scope, ['incoming.operations.manage', 'documents.metadata.view']);
        }
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0, 'due_date' => today()->toDateString()])->assertStatus(in_array($boundary, ['scope', 'confidentiality'], true) ? 404 : 403);
        $this->assertDatabaseCount('incoming_correspondence_operations', 0);
        $this->assertDatabaseCount('incoming_operation_audits', 0);
    }

    #[TestWith(['foreign'])]
    #[TestWith(['inactive'])]
    #[TestWith(['department'])]
    #[TestWith(['document_access'])]
    public function test_assignment_rejects_ineligible_users_and_departments(string $kind): void
    {
        [, $scope, $record] = $this->fixture();
        $user = User::factory()->create();
        $site = $kind === 'foreign' ? Site::factory()->create() : $scope->site;
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        if ($kind !== 'document_access') {
            $this->grant($user, $scope, ['documents.metadata.view']);
        }
        if ($kind === 'inactive') {
            $user->deactivate();
        }
        $data = ['responsible_user_id' => $user->id];
        if ($kind === 'department') {
            $department = Department::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
            $data['responsible_department_id'] = $department->id;
        }
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0] + $data)->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
        $this->assertDatabaseCount('incoming_operation_audits', 0);
        if ($kind !== 'department') {
            $this->getJson($this->url($scope, $record, '/operation-options'))->assertOk()->assertJsonMissing(['id' => $user->id, 'name' => $user->name]);
        }
    }

    #[TestWith(['update'])]
    #[TestWith(['close'])]
    #[TestWith(['link'])]
    public function test_audit_failure_rolls_back_operational_state_and_related_links(string $action): void
    {
        [, $scope, $record] = $this->fixture();
        $related = $action === 'link' ? $this->anotherRecord($record) : null;
        $event = 'eloquent.creating: '.IncomingOperationAudit::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope, $record), ['action' => $action, 'revision' => 0] + match ($action) {
                'update' => ['due_date' => today()->toDateString()], 'link' => ['related_id' => $related->id], default => [],
            })->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('incoming_correspondence_operations', 0);
        $this->assertDatabaseCount('incoming_operation_audits', 0);
        $this->assertDatabaseCount('incoming_correspondence_relations', 0);
        $this->assertSame('registered', $record->fresh()->status);
    }

    private function anotherRecord(IncomingCorrespondence $record): IncomingCorrespondence
    {
        $profile = $record->profile;
        $scope = $profile->documentScope();
        $actor = User::query()->findOrFail($record->created_by);
        $draft = app(ManageIncomingCorrespondence::class)->save($actor, $scope, ['type_id' => $record->type_id,
            'subject' => 'Related example', 'sender_organization' => 'Example sender', 'priority' => 'normal', 'channel' => 'post',
            'classification' => 'normal', 'correspondence_date' => today()->toDateString(), 'received_date' => today()->toDateString()]);

        return app(ManageIncomingCorrespondence::class)->register($actor, $scope, (string) $draft->id);
    }

    public function test_related_links_are_bidirectional_and_hidden_after_document_access_is_revoked(): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $other = $this->anotherRecord($record);
        $this->postJson($this->url($scope, $record), ['action' => 'link', 'revision' => 0, 'related_id' => $other->id])->assertOk();
        $this->getJson($this->url($scope, $other, ''))->assertOk()->assertJsonPath('related.0.id', $record->id);
        $this->getJson($this->url($scope, $record, '/operation-options').'&search=Related')->assertOk()->assertJsonPath('related.0.id', $other->id);
        DB::table('document_security_profiles')->where('id', $other->profile_id)->update(['restricted' => true]);
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonCount(0, 'related')->assertJsonMissing(['related_number' => $other->internal_number])->assertJsonMissing(['related_id' => $other->id]);
        $this->postJson($this->url($scope, $record), ['action' => 'unlink', 'revision' => 1, 'related_id' => $other->id])->assertNotFound();
        $admin = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => $admin->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        OrganizationMembership::query()->update(['active' => false]);
        $this->postJson($this->url($scope, $record), ['action' => 'unlink', 'revision' => 1, 'related_id' => $other->id])->assertOk();
        $this->assertDatabaseCount('incoming_correspondence_relations', 0);
        $this->assertDatabaseHas('incoming_operation_audits', ['action' => 'unlinked', 'actor_id' => $actor->id]);
    }

    public function test_register_filters_and_xlsx_export_enforce_confidentiality_and_preserve_safe_text(): void
    {
        [, $scope, $record] = $this->fixture();
        $other = $this->anotherRecord($record);
        DB::table('document_security_profiles')->where('id', $other->profile_id)->update(['restricted' => true]);
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0, 'status' => 'in_progress', 'due_date' => today()->subDay()->toDateString(), 'response_required' => true])->assertOk();
        $query = http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'search' => 'Synthetic', 'filter_status' => 'in_progress', 'filter_channel' => 'courier', 'filter_due' => 'overdue', 'filter_response' => 'pending']);
        $this->getJson('/api/v1/incoming-correspondence?'.$query)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record->id);
        $bytes = $this->get('/api/v1/incoming-correspondence/export?'.$query)->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'dcms-filtered-export-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(8, $sheet->getHighestDataRow());
            $this->assertSame('=Synthetic subject', $sheet->getCell('B8')->getValue());
            $this->assertSame('s', $sheet->getCell('B8')->getDataType());
            $this->assertSame('26/10/2026', $sheet->getCell('D8')->getFormattedValue());
            $this->assertSame('25/10/2026', $sheet->getCell('G8')->getFormattedValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->getJson('/api/v1/incoming-correspondence?company_id='.$scope->company_id.'&site_id='.$scope->site_id.'&filter_status=received')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_operational_audits_and_registered_identity_cannot_be_replaced_or_rewritten(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), ['action' => 'note', 'revision' => 0, 'note' => 'Immutable follow-up'])->assertOk();
        foreach ([fn () => DB::table('incoming_operation_audits')->update(['note' => 'Forged']), fn () => DB::table('incoming_operation_audits')->delete(),
            fn () => DB::table('incoming_operation_audits')->insertOrIgnore(['id' => 1, 'correspondence_id' => $record->id, 'actor_id' => $record->created_by, 'revision' => 1, 'action' => 'noted', 'note' => 'Forged', 'before' => '{}', 'after' => '{}']),
            fn () => DB::table('incoming_correspondences')->where('id', $record->id)->update(['channel' => 'email']),
            fn () => DB::table('incoming_correspondence_operations')->where('correspondence_id', $record->id)->update(['revision' => 2, 'status' => 'closed']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Protected operation was changed.');
            } catch (QueryException) {
            }
        }
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 1, 'internal_number' => 'FORGED'])->assertUnprocessable()->assertJsonValidationErrors('internal_number');
        $this->assertDatabaseHas('incoming_operation_audits', ['note' => 'Immutable follow-up']);
        $this->assertDatabaseHas('incoming_correspondences', ['id' => $record->id, 'channel' => 'courier']);
    }

    public function test_reasoned_metadata_correction_preserves_issuance_and_records_exact_changes(): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $identity = $record->only(['id', 'scope_id', 'profile_id', 'register_id', 'type_id', 'internal_number', 'registered_at', 'registered_by', 'created_by', 'created_at']);
        $ledger = DB::table('issued_document_numbers')->get()->toArray();
        $counters = DB::table('numbering_counters')->get()->toArray();
        $body = ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct transcription from original letter', 'details' => [
            'subject' => 'Corrected subject', 'sender_organization' => 'Corrected sender', 'sender_contact' => 'Contact', 'sender_email' => 'example@example.test', 'sender_phone' => '123',
            'external_reference' => 'EXT/001', 'correspondence_date' => '2026-10-24', 'received_date' => '2026-10-25', 'channel' => 'email', 'description_en' => 'Corrected description', 'description_lo' => 'ແກ້ໄຂ', 'priority' => 'urgent']];
        $this->postJson($this->url($scope, $record), $body)->assertOk()->assertJsonPath('data.subject', 'Corrected subject')->assertJsonPath('data.received_date', '2026-10-25')->assertJsonPath('data.operations.revision', 1);
        $this->assertEquals($identity, $record->fresh()->only(array_keys($identity)));
        $this->assertEquals($ledger, DB::table('issued_document_numbers')->get()->toArray());
        $this->assertEquals($counters, DB::table('numbering_counters')->get()->toArray());
        $audit = IncomingOperationAudit::query()->sole();
        $this->assertSame('=Synthetic subject', $audit->before['details']['subject']);
        $this->assertSame('Corrected subject', $audit->after['details']['subject']);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($body['note'], $audit->note);
        $this->assertNotNull($audit->created_at);
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonPath('history.0.before.details.subject', '=Synthetic subject')->assertJsonPath('history.0.after.details.subject', 'Corrected subject');
        $this->postJson($this->url($scope, $record), $body)->assertConflict();
        $this->assertDatabaseCount('incoming_operation_audits', 1);
    }

    #[TestWith([null])]
    #[TestWith(['   '])]
    #[TestWith(['Typo'])]
    #[TestWith(['..........'])]
    public function test_metadata_correction_requires_a_meaningful_reason(?string $reason): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'note' => $reason, 'details' => ['subject' => 'Corrected']])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame('=Synthetic subject', $record->fresh()->subject);
        $this->assertDatabaseCount('incoming_operation_audits', 0);
    }

    public function test_metadata_correction_rejects_protected_identity_invalid_dates_and_no_changes(): void
    {
        [, $scope, $record] = $this->fixture();
        $body = ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake'];
        foreach (['type_id', 'scope_id', 'profile_id', 'register_id', 'internal_number', 'registered_at', 'classification'] as $field) {
            $this->postJson($this->url($scope, $record), $body + ['details' => ['subject' => 'Corrected', $field => '999']])->assertUnprocessable()->assertJsonValidationErrors('details');
        }
        $this->postJson($this->url($scope, $record), $body + ['details' => ['received_date' => '2026-02-30']])->assertUnprocessable()->assertJsonValidationErrors('details.received_date');
        $this->postJson($this->url($scope, $record), $body + ['internal_number' => 'FAKE', 'details' => ['subject' => 'Corrected']])->assertUnprocessable()->assertJsonValidationErrors('internal_number');
        $this->postJson($this->url($scope, $record), $body + ['details' => ['subject' => $record->subject]])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('incoming_operation_audits', 0);
    }

    #[TestWith(['permission', 403])]
    #[TestWith(['membership', 403])]
    #[TestWith(['restricted', 404])]
    public function test_metadata_correction_requires_current_authority_and_document_access(string $boundary, int $status): void
    {
        [, $scope, $record, $role] = $this->fixture();
        if ($boundary === 'permission') {
            $role->permissions()->detach(Permission::query()->where('key', 'incoming.operations.manage')->value('id'));
        } elseif ($boundary === 'membership') {
            OrganizationMembership::query()->update(['active' => false]);
        } else {
            DB::table('document_security_profiles')->where('id', $record->profile_id)->update(['restricted' => true]);
        }
        $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected']])->assertStatus($status);
        $this->assertSame('=Synthetic subject', $record->fresh()->subject);
        $this->assertDatabaseCount('incoming_operation_audits', 0);
    }

    public function test_closed_incoming_requires_reasoned_reopening_before_metadata_correction(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => 0])->assertOk();
        $body = ['action' => 'correct_details', 'revision' => 1, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected']];
        $this->postJson($this->url($scope, $record), $body)->assertUnprocessable()->assertJsonPath('errors.operation.0', 'incomingClosed');
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => 1, 'note' => 'Reopen to correct transcription'])->assertOk();
        $this->postJson($this->url($scope, $record), array_replace($body, ['revision' => 2]))->assertOk();
    }

    #[TestWith(['audit'])]
    #[TestWith(['revision'])]
    public function test_failed_correction_revision_save_rolls_back_metadata_and_audit(string $stage): void
    {
        [, $scope, $record] = $this->fixture();
        $event = $stage === 'audit' ? 'eloquent.creating: '.IncomingOperationAudit::class : 'eloquent.saving: '.IncomingCorrespondenceOperation::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected']])->assertServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame('=Synthetic subject', $record->fresh()->subject);
        $this->assertDatabaseCount('incoming_operation_audits', 0);
        $this->assertDatabaseCount('incoming_correspondence_operations', 0);
    }

    public function test_database_correction_guard_rejects_mismatched_audit_values_and_identity_changes(): void
    {
        [$actor, , $record] = $this->fixture();
        $audit = IncomingOperationAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'revision' => 1, 'action' => 'updated',
            'note' => 'Correct original transcription', 'before' => ['details' => ['subject' => $record->subject]], 'after' => ['details' => ['subject' => 'Corrected']]]);
        foreach ([['subject' => 'Does not match audit'], ['subject' => 'Corrected', 'internal_number' => 'FORGED']] as $changes) {
            try {
                DB::table('incoming_correspondences')->where('id', $record->id)->update($changes + ['updated_by' => $actor->id, 'updated_at' => $audit->created_at]);
                $this->fail('Correction guard allowed unaudited or identity changes.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($record->subject, $record->fresh()->subject);
        $this->assertSame($record->internal_number, $record->fresh()->internal_number);
    }
}
