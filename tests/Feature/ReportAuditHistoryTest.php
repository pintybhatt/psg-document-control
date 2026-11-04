<?php

namespace Tests\Feature;

use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManagePhysicalCustody;
use App\Models\ControlledRevisionApproval;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ReportAuditHistoryTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function report(object $record, array $filters = [], string $path = 'reports'): string
    {
        return '/api/v1/'.$path.'?'.http_build_query($filters + ['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id, 'view' => 'audit']);
    }

    public function test_operational_audits_require_explicit_permission_and_redact_unrelated_values(): void
    {
        [$record, $actor] = $this->fixture();
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['reports.view']);
        $this->getJson($this->report($record))->assertForbidden();
        $this->grant($actor, $scope->authorizationScope(), ['documents.audit.view', 'incoming.register', 'incoming.operations.manage']);
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'AUDIT/IN', 'padding' => 6, 'active' => true]);
        $record = app(ManageIncomingCorrespondence::class)->register($actor, $scope, (string) $record->id);
        $this->postJson('/api/v1/incoming-correspondence/'.$record->id.'/operations', ['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'action' => 'correct_details', 'revision' => 0, 'note' => 'Synthetic metadata correction', 'details' => ['subject' => 'Corrected synthetic subject']])->assertOk();
        StorageEvent::create(['profile_id' => $record->profile_id, 'actor_id' => $actor->id, 'action' => 'custody.correct', 'reason' => 'Synthetic adjustment', 'before' => ['status' => 'stored', 'path' => '/private/VAULT-SECRET', 'token' => 'TOKEN-SECRET', 'related' => [987654]], 'after' => ['status' => 'missing', 'related' => [987654], 'sha256' => 'HASH-SECRET']]);

        $this->getJson($this->report($record, ['event' => 'number.issued']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.issued_number', $record->internal_number)->assertJsonPath('data.0.actor', $actor->name);
        $response = $this->getJson($this->report($record, ['event' => 'updated']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.before.subject', 'Synthetic storage test')->assertJsonPath('data.0.after.subject', 'Corrected synthetic subject');
        $this->assertNotEmpty($response->json('data.0.changes'));
        $redacted = $this->getJson($this->report($record, ['event' => 'custody.correct']))->assertOk()->assertJsonPath('data.0.before.status', 'stored')->assertJsonPath('data.0.after.status', 'missing');
        $redacted->assertDontSee('VAULT-SECRET')->assertDontSee('TOKEN-SECRET')->assertDontSee('HASH-SECRET')->assertDontSee('987654');
    }

    public function test_denied_file_attempts_require_security_permission_and_parent_metadata_access(): void
    {
        [$record] = $this->fixture();
        $viewer = User::factory()->create();
        $scope = $record->profile->documentScope();
        OrganizationMembership::factory()->for($viewer)->atSite(Site::findOrFail($scope->siteId))->create();
        $this->grant($viewer, $scope->authorizationScope(), ['documents.metadata.view', 'reports.view']);
        $this->signIn($viewer);
        $version = (string) Str::uuid();
        $this->getJson($this->url($record, 'versions/'.$version.'/preview'))->assertNotFound();
        $this->getJson($this->url($record, 'versions/'.$version.'/download'))->assertNotFound();
        $this->getJson($this->report($record, ['view' => 'security']))->assertForbidden();
        $this->grant($viewer, $scope->authorizationScope(), ['security.audit.view']);
        $filters = ['view' => 'security', 'operation' => 'preview', 'outcome' => 'denied'];

        $response = $this->getJson($this->report($record, $filters))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.events', 2)->assertJsonPath('capabilities.include_unattributed', false);
        $response->assertDontSee($version);
        $this->getJson($this->report($record, ['view' => 'security', 'include_unattributed' => 1]))->assertForbidden();
        $record->profile->update(['restricted' => true]);
        $this->getJson($this->report($record, $filters))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    public function test_unattributable_requests_are_global_administrator_only_and_never_return_raw_payloads(): void
    {
        [$record] = $this->fixture('incoming', 'confidential');
        $event = StorageEvent::create(['action' => 'request.denied', 'reason' => '500', 'after' => ['route' => 'api.v1.storage.attachments.preview', 'record' => 'SECRET-RECORD', 'version' => 'SECRET-VERSION', 'path' => '/private/VAULT-SECRET', 'exception' => 'TOKEN-SECRET']]);

        $result = $this->getJson($this->report($record, ['view' => 'security', 'include_unattributed' => 1, 'operation' => 'preview', 'event' => 'request.denied']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.href', null)->assertJsonPath('data.0.event_en', 'File preview denied');
        $result->assertDontSee('SECRET-RECORD')->assertDontSee('SECRET-VERSION')->assertDontSee('VAULT-SECRET')->assertDontSee('TOKEN-SECRET');
        $this->assertSame('SECRET-RECORD', $event->fresh()->after['record']);
    }

    public function test_event_period_is_laos_local_and_does_not_filter_by_document_date(): void
    {
        [$record, $actor] = $this->fixture();
        $this->grant($actor, $record->profile->documentScope()->authorizationScope(), ['documents.audit.view']);
        $event = new StorageEvent(['profile_id' => $record->profile_id, 'actor_id' => $actor->id, 'action' => 'custody.inventory', 'reason' => 'Synthetic event period']);
        $event->created_at = $event->updated_at = '2026-10-26 18:05:00';
        $event->save();

        $this->getJson($this->report($record, ['event' => 'custody.inventory', 'date_from' => '2026-10-27', 'date_to' => '2026-10-27']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.timestamp', '2026-10-26T18:05:00.000000Z');
        $this->getJson($this->report($record, ['event' => 'custody.inventory', 'date_from' => '2026-10-26', 'date_to' => '2026-10-26']))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_security_exports_require_separate_permission_and_redact_event_payloads(): void
    {
        [$record, $actor] = $this->fixture();
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['reports.view']);
        StorageEvent::create(['profile_id' => $record->profile_id, 'actor_id' => $actor->id, 'action' => 'attachment.download', 'after' => ['path' => '/private/SECRET-PATH', 'version' => 'SECRET-VERSION']]);
        $filters = ['view' => 'security', 'event' => 'attachment.download', 'format' => 'xlsx'];
        $this->get($this->report($record, $filters, 'reports/export'))->assertForbidden();
        $this->grant($actor, $scope->authorizationScope(), ['security.audit.view']);
        $this->getJson($this->report($record, ['view' => 'security', 'event' => 'attachment.download', 'outcome' => 'allowed']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event_en', 'Protected-file download served')->assertDontSee('SECRET-PATH')->assertDontSee('SECRET-VERSION');
        $response = $this->get($this->report($record, $filters, 'reports/export'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dcms-audit-');
        try {
            file_put_contents($path, $response->getContent());
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame('Protected-file download served', $sheet->getCell('C8')->getValue());
            $this->assertStringNotContainsString('SECRET-', json_encode($sheet->toArray()));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->assertDatabaseHas('document_security_audits', ['actor_id' => $actor->id, 'event' => 'access.allowed', 'operation' => 'metadata']);
    }

    public function test_approval_and_review_events_have_consistent_bilingual_report_lookup_and_export_descriptions(): void
    {
        $this->travelTo('2026-10-28 10:00:00');
        [$record, $actor] = $this->fixture('controlled');
        $scope = $record->profile->documentScope();
        $this->getJson($this->report($record))->assertForbidden();
        $this->grant($actor, $scope->authorizationScope(), ['documents.audit.view', 'controlled.approvals.record', 'controlled.reviews.record']);
        $manager = app(ManageControlledDocuments::class);
        $revision = $record->revisions()->sole();
        foreach (['submitted', 'rejected', 'submitted', 'approved'] as $decision) {
            $record = $manager->recordApproval($actor, $scope, (string) $record->id, (string) $revision->id, ['lock_version' => $record->lock_version, 'decision' => $decision]);
        }
        $evidence = ControlledRevisionApproval::query()->orderBy('id')->get()->map->getAttributes()->all();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'AUDIT/CD', 'padding' => 4, 'active' => true]);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version]);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $revision->id, 'reason' => 'Synthetic release']);
        $manager->recordReview($actor, $scope, (string) $record->id, (string) $revision->id, ['lock_version' => $record->lock_version, 'outcome' => 'no_changes_required']);
        $lookups = collect($this->getJson($this->report($record, [], 'reports/lookups'))->assertOk()->json('events'))->keyBy('value');
        $descriptions = [
            'approval_submitted' => ['Submitted / resubmitted for approval', 'ສົ່ງ / ສົ່ງຄືນເພື່ອອະນຸມັດ'],
            'approval_approved' => ['Revision approval recorded', 'ບັນທຶກການອະນຸມັດສະບັບ'],
            'approval_rejected' => ['Revision rejection recorded', 'ບັນທຶກການປະຕິເສດສະບັບ'],
            'review_completed' => ['Document review completed', 'ທົບທວນເອກະສານສຳເລັດ'],
        ];
        foreach ($descriptions as $event => [$english, $lao]) {
            $this->assertSame($english, $lookups[$event]['name_en']);
            $this->assertSame($lao, $lookups[$event]['name_lo']);
            foreach (['en' => $english, 'lo' => $lao] as $language => $description) {
                $filters = ['event' => $event, 'language' => $language];
                $this->getJson($this->report($record, $filters))->assertOk()->assertJsonPath('meta.total', $event === 'approval_submitted' ? 2 : 1)
                    ->assertJsonPath('data.0.event', $description)->assertJsonPath('data.0.actor', $actor->name)->assertJsonPath('data.0.timestamp', '2026-10-28T10:00:00.000000Z');
                $response = $this->get($this->report($record, $filters + ['format' => 'xlsx'], 'reports/export'))->assertOk();
                $path = tempnam(sys_get_temp_dir(), 'dcms-event-');
                try {
                    file_put_contents($path, $response->getContent());
                    $book = IOFactory::load($path);
                    $this->assertSame($description.' · '.($language === 'lo' ? 'ສະບັບປັບປຸງ' : 'Revision').' R00', $book->getActiveSheet()->getCell('C8')->getValue());
                    $book->disconnectWorksheets();
                } finally {
                    unlink($path);
                }
            }
        }
        $this->assertSame($evidence, ControlledRevisionApproval::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_history_identifies_authorized_revision_and_physical_copy_without_raw_identifiers(): void
    {
        [$record, $actor] = $this->fixture('controlled');
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['documents.audit.view']);
        $this->getJson($this->report($record, ['event' => 'created']))->assertOk()->assertJsonPath('data.0.revision_identifier', 'R00');
        $custody = app(ManagePhysicalCustody::class);
        $location = $custody->location($actor, $scope, 'controlled', (string) $record->id, ['room' => 'Synthetic archive']);
        $copy = $custody->create($actor, $scope, 'controlled', (string) $record->id, $record->revisions->first()->id, ['identifier' => 'AUDIT/ORIGINAL', 'kind' => 'original', 'location_id' => $location->id, 'reason' => 'Synthetic original']);
        $this->getJson($this->report($record, ['event' => 'copy.created']))->assertOk()->assertJsonPath('data.0.physical_identifier', 'AUDIT/ORIGINAL')->assertDontSee($copy->id);
    }
}
