<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\OrganizationMembership;
use App\Models\OutgoingCorrespondence;
use App\Models\OutgoingOperation;
use App\Models\OutgoingOperationAudit;
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

class OutgoingOperationsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, OutgoingCorrespondence, Role} */
    private function fixture(bool $registered = true): array
    {
        $this->travelTo(now()->setDate(2026, 10, 26));
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['outgoing.drafts.manage', 'outgoing.register', 'documents.metadata.view', 'numbering.manage', 'numbering.issue', 'outgoing.operations.manage', 'outgoing.operations.reopen']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        foreach (['internal_register' => 'OPS/OUT', 'official_outgoing' => 'OPS/OFFICIAL'] as $purpose => $prefix) {
            app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => $purpose, 'prefix' => $prefix, 'padding' => 6, 'reset_policy' => 'annual', 'active' => true]);
        }
        $unit = new DocumentScope($site->company_id, $site->id);
        $record = app(ManageOutgoingCorrespondence::class)->save($actor, $unit, ['type_id' => $type->id, 'signatory_name' => 'Example signatory', 'subject' => 'Synthetic outgoing', 'document_date' => '2026-10-25', 'priority' => 'normal', 'classification' => 'normal',
            'recipients' => [['kind' => 'primary', 'organization' => 'First recipient'], ['kind' => 'primary', 'organization' => 'Second recipient'], ['kind' => 'copy', 'organization' => 'Copy recipient']]]);
        if ($registered) {
            $record = app(ManageOutgoingCorrespondence::class)->register($actor, $unit, (string) $record->id);
        }
        $this->signIn($actor);

        return [$actor, $scope, $record, $role];
    }

    private function url(AuthorizationScope $scope, OutgoingCorrespondence $record, string $suffix = '/operations'): string
    {
        return '/api/v1/outgoing-correspondence/'.$record->id.$suffix.'?'.http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
    }

    /** @return array<string, mixed> */
    private function dispatchData(int $revision = 0): array
    {
        return ['action' => 'dispatch', 'revision' => $revision, 'dispatch_date' => '2026-10-25', 'dispatch_channel' => 'courier', 'dispatch_reference' => 'SYNTHETIC/TRACK/1', 'dispatch_remarks' => 'Example dispatch'];
    }

    #[TestWith(['registered'])]
    #[TestWith(['dispatched'])]
    #[TestWith(['delivered'])]
    public function test_dispatch_delivery_and_optional_confirmation_closure_preserve_issued_identity(string $stage): void
    {
        [$actor, $scope, $record, $role] = $this->fixture();
        $identity = $record->getRawOriginal();
        $issuances = DB::table('issued_document_numbers')->orderBy('id')->get()->toArray();
        $revision = 0;
        if ($stage !== 'registered') {
            $this->postJson($this->url($scope, $record), $this->dispatchData($revision++))->assertOk()->assertJsonPath('data.operations.status', 'dispatched');
            $this->postJson($this->url($scope, $record), $this->dispatchData($revision))->assertUnprocessable();
        }
        if ($stage === 'delivered') {
            foreach ($record->recipients()->where('kind', 'primary')->get() as $index => $recipient) {
                $this->postJson($this->url($scope, $record), ['action' => 'delivery', 'revision' => $revision++, 'recipient_id' => $recipient->id, 'delivery_date' => '2026-10-26', 'acknowledged_by' => 'Example receiver', 'acknowledgment_date' => '2026-10-26', 'acknowledgment_reference' => 'SIGNED/EXAMPLE'])->assertOk()->assertJsonPath('data.operations.status', $index === 0 ? 'dispatched' : 'delivered');
            }
            $this->assertDatabaseCount('outgoing_deliveries', 2);
        }
        if ($stage === 'registered') {
            $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('note');
        }
        $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => $revision++, 'note' => 'Handling complete'])->assertOk()->assertJsonPath('data.operations.status', 'closed')->assertJsonPath('data.operations.closed_by', $actor->id);
        $this->postJson($this->url($scope, $record), ['action' => 'note', 'revision' => $revision, 'note' => 'Blocked while closed'])->assertUnprocessable();
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('note');
        $permission = Permission::query()->where('key', 'outgoing.operations.reopen')->value('id');
        $role->permissions()->detach($permission);
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision, 'note' => 'Additional handling'])->assertForbidden();
        $role->permissions()->attach($permission);
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision, 'note' => 'Additional handling'])->assertOk()->assertJsonPath('data.operations.status', $stage)->assertJsonPath('data.operations.closed_at', null);
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonPath('history.0.actor_name', $actor->name);
        $this->assertSame($identity, $record->fresh()->getRawOriginal());
        $this->assertEquals($issuances, DB::table('issued_document_numbers')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('outgoing_registration_links', 2);
        $this->assertDatabaseHas('outgoing_operation_audits', ['action' => 'reopen', 'actor_id' => $actor->id, 'note' => 'Additional handling']);
    }

    public function test_corrections_require_reasons_and_keep_previous_dispatch_and_acknowledgment_details(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), $this->dispatchData())->assertOk();
        $recipient = $record->recipients()->first();
        $body = ['action' => 'delivery', 'revision' => 1, 'recipient_id' => $recipient->id, 'delivery_date' => '2026-10-26', 'acknowledged_by' => 'Example receiver'];
        $this->postJson($this->url($scope, $record), $body)->assertOk();
        $this->postJson($this->url($scope, $record), array_replace($this->dispatchData(2), ['action' => 'correct_dispatch']))->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->url($scope, $record), array_replace($this->dispatchData(2), ['action' => 'correct_dispatch', 'dispatch_channel' => 'hand_delivery', 'note' => 'Correct dispatch method']))->assertOk();
        $undo = ['action' => 'correct_dispatch', 'revision' => 3, 'dispatch_date' => null, 'dispatch_channel' => null, 'dispatch_reference' => null, 'dispatch_remarks' => null, 'note' => 'Dispatch recorded in error'];
        $this->postJson($this->url($scope, $record), $undo)->assertUnprocessable()->assertJsonPath('errors.dispatch_date.0', 'outgoingClearDeliveriesFirst');
        $body = ['action' => 'delivery', 'revision' => 3, 'recipient_id' => $recipient->id, 'delivery_date' => null];
        $this->postJson($this->url($scope, $record), $body)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->url($scope, $record), $body + ['note' => 'Confirmation recorded for wrong recipient'])->assertOk()->assertJsonPath('data.recipients.0.delivery.delivery_date', null)->assertJsonPath('data.recipients.0.delivery.acknowledged_by', null);
        $this->assertDatabaseHas('outgoing_operation_audits', ['action' => 'correct_dispatch', 'note' => 'Correct dispatch method']);
        $this->assertSame('Example receiver', OutgoingOperationAudit::query()->latest('id')->first()->before['delivery']['acknowledged_by']);
        $this->postJson($this->url($scope, $record), array_replace($undo, ['revision' => 4]))->assertOk()->assertJsonPath('data.operations.status', 'registered')->assertJsonPath('data.operations.dispatch_date', null);
        $this->assertSame('hand_delivery', OutgoingOperationAudit::query()->latest('id')->first()->before['dispatch_channel']);
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $record->id, 'status' => 'registered']);
    }

    public function test_invalid_dates_are_rejected_without_overwriting_dispatch_or_delivery(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), array_replace($this->dispatchData(), ['dispatch_date' => '2026-10-27']))->assertUnprocessable()->assertJsonValidationErrors('dispatch_date');
        $this->postJson($this->url($scope, $record), $this->dispatchData())->assertOk();
        $body = ['action' => 'delivery', 'revision' => 1, 'recipient_id' => $record->recipients()->first()->id, 'delivery_date' => '2026-10-24'];
        $this->postJson($this->url($scope, $record), $body)->assertUnprocessable()->assertJsonValidationErrors('delivery_date');
        $this->postJson($this->url($scope, $record), array_replace($body, ['delivery_date' => '2026-10-26', 'acknowledgment_date' => '2026-10-25']))->assertUnprocessable()->assertJsonValidationErrors('acknowledgment_date');
        $this->assertDatabaseCount('outgoing_operation_audits', 1);
        $this->assertDatabaseCount('outgoing_deliveries', 0);
    }

    #[TestWith(['draft', 422])]
    #[TestWith(['before_dispatch', 422])]
    #[TestWith(['stale', 409])]
    #[TestWith(['foreign_recipient', 404])]
    public function test_invalid_or_stale_operations_do_not_change_delivery_state(string $kind, int $status): void
    {
        [$actor, $scope, $record] = $this->fixture($kind !== 'draft');
        $body = $this->dispatchData();
        if ($kind !== 'draft') {
            if ($kind !== 'before_dispatch') {
                $this->postJson($this->url($scope, $record), $body)->assertOk();
            }
            $recipientId = $record->recipients()->first()->id;
            if ($kind === 'foreign_recipient') {
                $other = app(ManageOutgoingCorrespondence::class)->save($actor, new DocumentScope($scope->company_id, $scope->site_id), [
                    'type_id' => $record->type_id, 'signatory_name' => 'Example signatory', 'subject' => 'Different correspondence',
                    'document_date' => '2026-10-25', 'priority' => 'normal', 'classification' => 'normal',
                    'recipients' => [['kind' => 'primary', 'organization' => 'Other document recipient']],
                ]);
                $recipientId = $other->recipients()->first()->id;
            }
            $body = ['action' => 'delivery', 'revision' => $kind === 'stale' ? 0 : 1, 'recipient_id' => $recipientId, 'delivery_date' => '2026-10-26'];
            if ($kind === 'before_dispatch') {
                $body['revision'] = 0;
            }
        }
        $this->postJson($this->url($scope, $record), $body)->assertStatus($status);
        $this->assertDatabaseCount('outgoing_deliveries', 0);
    }

    #[TestWith(['permission', 403])]
    #[TestWith(['membership', 403])]
    #[TestWith(['restricted', 404])]
    #[TestWith(['scope', 404])]
    public function test_dispatch_requires_current_authority_and_exact_document_access(string $boundary, int $status): void
    {
        [$actor, $scope, $record, $role] = $this->fixture();
        if ($boundary === 'permission') {
            $role->permissions()->detach(Permission::query()->where('key', 'outgoing.operations.manage')->value('id'));
        } elseif ($boundary === 'membership') {
            OrganizationMembership::query()->update(['active' => false]);
        } elseif ($boundary === 'restricted') {
            DB::table('document_security_profiles')->where('id', $record->profile_id)->update(['restricted' => true]);
        } else {
            $site = Site::factory()->create();
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
            $this->grant($actor, $scope, ['documents.metadata.view', 'outgoing.operations.manage']);
        }
        $this->postJson($this->url($scope, $record), $this->dispatchData())->assertStatus($status);
        $this->assertDatabaseCount('outgoing_operations', 0);
        $this->assertDatabaseCount('outgoing_operation_audits', 0);
    }

    #[TestWith(['dispatch'])]
    #[TestWith(['delivery'])]
    #[TestWith(['close'])]
    #[TestWith(['link'])]
    public function test_failed_operational_audit_rolls_back_state_delivery_and_related_links(string $action): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $revision = 0;
        $body = ['action' => $action, 'revision' => 0, 'note' => 'Example operation'];
        if ($action === 'dispatch') {
            $body = $this->dispatchData();
        } elseif ($action === 'delivery') {
            $this->postJson($this->url($scope, $record), $this->dispatchData())->assertOk();
            $revision = 1;
            $body = ['action' => 'delivery', 'revision' => 1, 'recipient_id' => $record->recipients()->first()->id, 'delivery_date' => '2026-10-26'];
        } elseif ($action === 'link') {
            $body['incoming_id'] = $this->incoming($actor, $scope, $record)->id;
        }
        $event = 'eloquent.creating: '.OutgoingOperationAudit::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope, $record), $body)->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('outgoing_deliveries', 0);
        $this->assertDatabaseCount('outgoing_incoming_links', 0);
        $this->assertDatabaseCount('outgoing_operation_audits', $revision);
        $this->assertSame($revision, $record->fresh()->operation?->revision ?? 0);
    }

    private function incoming(User $actor, AuthorizationScope $scope, OutgoingCorrespondence $record): IncomingCorrespondence
    {
        $this->grant($actor, $scope, ['incoming.drafts.manage', 'incoming.register']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $record->type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'prefix' => 'OPS/IN', 'padding' => 6, 'reset_policy' => 'annual', 'active' => true]);
        $unit = new DocumentScope($scope->company_id, $scope->site_id);
        $draft = app(ManageIncomingCorrespondence::class)->save($actor, $unit, ['type_id' => $record->type_id, 'subject' => 'Related synthetic incoming', 'sender_organization' => 'Example sender', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'classification' => 'normal', 'priority' => 'normal', 'channel' => 'email']);

        return app(ManageIncomingCorrespondence::class)->register($actor, $unit, (string) $draft->id);
    }

    public function test_related_incoming_is_hidden_after_access_revocation_and_active_global_admin_can_still_operate(): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $incoming = $this->incoming($actor, $scope, $record);
        $this->postJson($this->url($scope, $record), ['action' => 'link', 'revision' => 0, 'incoming_id' => $incoming->id])->assertOk();
        $this->getJson($this->url($scope, $record, '/operation-options').'&search=Related')->assertOk()->assertJsonPath('data.0.id', $incoming->id);
        DB::table('document_security_profiles')->where('id', $incoming->profile_id)->update(['restricted' => true]);
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonCount(0, 'related_incoming')->assertJsonMissing(['incoming_number' => $incoming->internal_number])->assertJsonMissing(['incoming_id' => $incoming->id]);
        $this->postJson($this->url($scope, $record), ['action' => 'unlink', 'revision' => 1, 'incoming_id' => $incoming->id])->assertNotFound();
        $admin = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => $admin->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        OrganizationMembership::query()->update(['active' => false]);
        $this->postJson($this->url($scope, $record), ['action' => 'unlink', 'revision' => 1, 'incoming_id' => $incoming->id])->assertOk();
        $this->postJson($this->url($scope, $record), $this->dispatchData(2))->assertOk();
        $this->assertTrue(DocumentSecurityAudit::query()->where('reason', 'system_administrator')->exists());
    }

    public function test_database_guards_protect_audits_recipient_ownership_closed_delivery_and_number_identity(): void
    {
        [, $scope, $record] = $this->fixture();
        $this->postJson($this->url($scope, $record), $this->dispatchData())->assertOk();
        $recipient = $record->recipients()->first();
        $this->postJson($this->url($scope, $record), ['action' => 'delivery', 'revision' => 1, 'recipient_id' => $recipient->id, 'delivery_date' => '2026-10-26'])->assertOk();
        $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => 2])->assertOk();
        foreach ([fn () => DB::table('outgoing_operation_audits')->update(['note' => 'FORGED']), fn () => DB::table('outgoing_operation_audits')->delete(),
            fn () => DB::table('outgoing_deliveries')->where('recipient_id', $recipient->id)->update(['delivery_date' => '2026-10-25']),
            fn () => DB::table('outgoing_deliveries')->insert(['recipient_id' => $record->recipients()->where('kind', 'copy')->first()->id, 'correspondence_id' => 999999, 'delivery_date' => '2026-10-26']),
            fn () => DB::table('outgoing_operations')->where('correspondence_id', $record->id)->update(['revision' => 4, 'status' => 'registered']),
            fn () => DB::table('outgoing_correspondences')->where('id', $record->id)->update(['official_number' => 'FORGED']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Protected outgoing operation was changed.');
            } catch (QueryException) {
            }
        }
        $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => 3, 'note' => 'Correction', 'official_number' => 'FORGED'])->assertUnprocessable()->assertJsonValidationErrors('official_number');
        $this->assertDatabaseHas('outgoing_operations', ['status' => 'closed', 'revision' => 3]);
    }

    public function test_undispatched_metadata_and_recipient_corrections_preserve_both_issuances(): void
    {
        [$actor, $scope, $record] = $this->fixture();
        $identity = $record->only(['id', 'scope_id', 'profile_id', 'register_id', 'type_id', 'internal_number', 'official_number', 'registered_at', 'registered_by', 'created_at']);
        $ledger = DB::table('issued_document_numbers')->get()->toArray();
        $counters = DB::table('numbering_counters')->get()->toArray();
        $recipient = $record->recipients()->first();
        $original = $recipient->organization;
        $details = ['subject' => 'Corrected subject', 'signatory_name' => 'Correct signatory', 'signatory_title' => 'Officer', 'document_date' => '2026-10-24', 'description_en' => 'Corrected', 'description_lo' => 'ແກ້ໄຂ', 'priority' => 'urgent',
            'recipients' => [['id' => $recipient->id, 'organization' => 'Correct recipient', 'contact_name' => 'Contact', 'email' => 'example@example.test', 'phone' => '123', 'address' => 'Address']]];
        $body = ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct transcription before dispatch', 'details' => $details];
        $this->postJson($this->url($scope, $record), $body)->assertOk()->assertJsonPath('data.subject', 'Corrected subject')->assertJsonPath('data.recipients.0.id', $recipient->id)->assertJsonPath('data.recipients.0.organization', 'Correct recipient')->assertJsonPath('data.operations.revision', 1);
        $this->assertEquals($identity, $record->fresh()->only(array_keys($identity)));
        $this->assertEquals($ledger, DB::table('issued_document_numbers')->get()->toArray());
        $this->assertEquals($counters, DB::table('numbering_counters')->get()->toArray());
        $this->assertSame('primary', $recipient->fresh()->kind);
        $audit = OutgoingOperationAudit::query()->sole();
        $this->assertSame($original, $audit->before['details']['recipients'][$recipient->id]['organization']);
        $this->assertSame('Correct recipient', $audit->after['details']['recipients'][$recipient->id]['organization']);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($body['note'], $audit->note);
        $this->getJson($this->url($scope, $record, ''))->assertOk()->assertJsonPath('history.0.after.details.subject', 'Corrected subject');
        $this->postJson($this->url($scope, $record), $body)->assertConflict();
        $this->assertDatabaseCount('outgoing_operation_audits', 1);
    }

    #[TestWith(['dispatched'])]
    #[TestWith(['delivered'])]
    #[TestWith(['closed'])]
    #[TestWith(['undone_dispatch'])]
    #[TestWith(['reopened_dispatch'])]
    public function test_post_dispatch_or_closed_metadata_corrections_cannot_change_sent_details(string $stage): void
    {
        [, $scope, $record] = $this->fixture();
        $revision = 0;
        if ($stage !== 'closed') {
            $this->postJson($this->url($scope, $record), $this->dispatchData($revision++))->assertOk();
        }
        if ($stage === 'delivered') {
            $this->postJson($this->url($scope, $record), ['action' => 'delivery', 'revision' => $revision++, 'recipient_id' => $record->recipients()->first()->id, 'delivery_date' => '2026-10-26'])->assertOk();
        } elseif ($stage === 'undone_dispatch') {
            $this->postJson($this->url($scope, $record), ['action' => 'correct_dispatch', 'revision' => $revision++, 'dispatch_date' => null, 'dispatch_channel' => null, 'note' => 'Dispatch recorded in error'])->assertOk();
        } elseif (in_array($stage, ['closed', 'reopened_dispatch'], true)) {
            $this->postJson($this->url($scope, $record), ['action' => 'close', 'revision' => $revision++, 'note' => 'End of handling'])->assertOk();
            if ($stage === 'reopened_dispatch') {
                $this->postJson($this->url($scope, $record), ['action' => 'reopen', 'revision' => $revision++, 'note' => 'Additional follow-up required'])->assertOk();
            }
        }
        $original = $record->fresh()->getRawOriginal();
        $historyCount = OutgoingOperationAudit::query()->count();
        $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => $revision, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected']])->assertUnprocessable();
        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('outgoing_operation_audits', $historyCount);
    }

    public function test_outgoing_corrections_reject_identity_recipient_reassignment_and_missing_reasons(): void
    {
        [, $scope, $record] = $this->fixture();
        $body = ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake'];
        $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'details' => ['subject' => 'Corrected']])->assertUnprocessable()->assertJsonValidationErrors('note');
        foreach (['type_id', 'scope_id', 'register_id', 'classification', 'internal_number', 'official_number', 'registered_at'] as $field) {
            $this->postJson($this->url($scope, $record), $body + ['details' => ['subject' => 'Corrected', $field => '999']])->assertUnprocessable()->assertJsonValidationErrors('details');
        }
        $this->postJson($this->url($scope, $record), $body + ['details' => ['recipients' => [['id' => $record->recipients()->first()->id, 'organization' => 'Correct', 'kind' => 'copy']]]])->assertUnprocessable();
        $this->postJson($this->url($scope, $record), $body + ['details' => ['recipients' => [['id' => 99999, 'organization' => 'Correct']]]])->assertNotFound();
        $this->assertDatabaseCount('outgoing_operation_audits', 0);
    }

    #[TestWith(['permission', 403])]
    #[TestWith(['membership', 403])]
    #[TestWith(['restricted', 404])]
    public function test_outgoing_corrections_require_current_authority_and_document_access(string $boundary, int $status): void
    {
        [, $scope, $record, $role] = $this->fixture();
        if ($boundary === 'permission') {
            $role->permissions()->detach(Permission::query()->where('key', 'outgoing.operations.manage')->value('id'));
        } elseif ($boundary === 'membership') {
            OrganizationMembership::query()->update(['active' => false]);
        } else {
            DB::table('document_security_profiles')->where('id', $record->profile_id)->update(['restricted' => true]);
        }
        $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected']])->assertStatus($status);
        $this->assertDatabaseCount('outgoing_operation_audits', 0);
    }

    #[TestWith(['audit'])]
    #[TestWith(['revision'])]
    public function test_failed_outgoing_correction_save_rolls_back_metadata_recipients_and_audit(string $stage): void
    {
        [, $scope, $record] = $this->fixture();
        $original = $record->getRawOriginal();
        $recipient = $record->recipients()->first();
        $originalRecipient = $recipient->getRawOriginal();
        $event = $stage === 'audit' ? 'eloquent.creating: '.OutgoingOperationAudit::class : 'eloquent.saving: '.OutgoingOperation::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope, $record), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct original data-entry mistake', 'details' => ['subject' => 'Corrected', 'recipients' => [['id' => $recipient->id, 'organization' => 'Corrected recipient']]]])->assertServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertSame($originalRecipient, $recipient->fresh()->getRawOriginal());
        $this->assertDatabaseCount('outgoing_operation_audits', 0);
        $this->assertDatabaseCount('outgoing_operations', 0);
    }

    public function test_database_correction_guard_rejects_mismatched_audit_values_and_identity_changes(): void
    {
        [$actor, , $record] = $this->fixture();
        $audit = OutgoingOperationAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'revision' => 1, 'action' => 'note',
            'note' => 'Correct original transcription', 'before' => ['details' => ['subject' => $record->subject]], 'after' => ['details' => ['subject' => 'Corrected']]]);
        foreach ([['subject' => 'Does not match audit'], ['subject' => 'Corrected', 'internal_number' => 'FORGED']] as $changes) {
            try {
                DB::table('outgoing_correspondences')->where('id', $record->id)->update($changes + ['updated_by' => $actor->id, 'updated_at' => $audit->created_at]);
                $this->fail('Correction guard allowed unaudited or identity changes.');
            } catch (QueryException) {
            }
        }
        $this->assertSame($record->subject, $record->fresh()->subject);
        $this->assertSame($record->internal_number, $record->fresh()->internal_number);
    }
}
