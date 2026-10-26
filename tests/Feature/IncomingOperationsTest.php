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
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class IncomingOperationsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, IncomingCorrespondence, Role} */
    private function fixture(): array
    {
        $this->freezeTime();
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

    public function test_register_filters_and_csv_export_enforce_confidentiality_and_escape_spreadsheet_formulas(): void
    {
        [, $scope, $record] = $this->fixture();
        $other = $this->anotherRecord($record);
        DB::table('document_security_profiles')->where('id', $other->profile_id)->update(['restricted' => true]);
        $this->postJson($this->url($scope, $record), ['action' => 'update', 'revision' => 0, 'status' => 'in_progress', 'due_date' => today()->subDay()->toDateString(), 'response_required' => true])->assertOk();
        $query = http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'search' => 'Synthetic', 'filter_status' => 'in_progress', 'filter_channel' => 'courier', 'filter_due' => 'overdue', 'filter_response' => 'pending']);
        $this->getJson('/api/v1/incoming-correspondence?'.$query)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record->id);
        $csv = $this->get('/api/v1/incoming-correspondence/export?'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Synthetic subject", $csv);
        $this->assertStringNotContainsString('Related example', $csv);
        $this->assertStringContainsString('26/10/2026', $csv);
        $this->assertStringContainsString('25/10/2026', $csv);
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
}
