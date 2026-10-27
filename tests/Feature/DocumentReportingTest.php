<?php

namespace Tests\Feature;

use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManagePhysicalCustody;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityAudit;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentReportingTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function reportUrl(object $record, string $path = 'dashboard', array $filters = []): string
    {
        return '/api/v1/'.$path.'?'.http_build_query($filters + ['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
    }

    public function test_dashboard_lifecycle_totals_do_not_count_physical_copies_as_documents(): void
    {
        [$record, $actor] = $this->fixture();
        $this->freezeTime();
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['incoming.register', 'incoming.operations.manage', 'incoming.operations.reopen']);
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'REPORT/IN', 'padding' => 6, 'active' => true]);
        $records = [$record];
        for ($i = 0; $i < 2; $i++) {
            $records[] = app(ManageIncomingCorrespondence::class)->save($actor, $scope, ['type_id' => $record->type_id, 'classification' => 'normal', 'subject' => 'Synthetic report '.$i, 'sender_organization' => 'Example', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email', 'priority' => 'normal']);
        }
        foreach ($records as $index => $item) {
            app(ManageIncomingCorrespondence::class)->register($actor, $scope, (string) $item->id);
            $this->postJson('/api/v1/incoming-correspondence/'.$item->id.'/operations', ['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'action' => 'update', 'revision' => 0, 'due_date' => ($index === 1 ? today()->addDays(7) : today()->subDay())->format('Y-m-d'), 'response_required' => $index === 0])->assertOk();
            if ($index === 2) {
                $this->postJson('/api/v1/incoming-correspondence/'.$item->id.'/operations', ['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'action' => 'close', 'revision' => 1])->assertOk();
            }
        }
        $custody = app(ManagePhysicalCustody::class);
        $location = $custody->location($actor, $scope, 'incoming', (string) $record->id, ['room' => 'Synthetic reporting room']);
        foreach (['original', 'copy'] as $kind) {
            $copy = $custody->create($actor, $scope, 'incoming', (string) $record->id, null, ['identifier' => 'REPORT/'.$kind, 'kind' => $kind, 'location_id' => $location->id, 'reason' => 'Synthetic reporting fixture']);
            if ($kind === 'original') {
                $custody->change($actor, $scope, 'incoming', (string) $record->id, null, $copy->id, ['action' => 'checkout', 'lock_version' => 1, 'custodian_id' => $actor->id, 'checkout_date' => today()->subDays(2)->format('Y-m-d'), 'expected_return_date' => today()->subDay()->format('Y-m-d'), 'reason' => 'Synthetic checkout']);
            }
        }

        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.incoming', 3)->assertJsonPath('totals.open_incoming', 2)->assertJsonPath('totals.registered_incoming', 3)
            ->assertJsonPath('totals.pending_response', 1)->assertJsonPath('totals.overdue', 1)->assertJsonPath('totals.due_soon', 1)->assertJsonPath('totals.closed', 1)
            ->assertJsonPath('totals.physical_copies', 2)->assertJsonPath('totals.checkout_overdue', 1)->assertJsonPath('totals.originals', 1)->assertJsonPath('totals.stored', 1);
        $this->postJson('/api/v1/incoming-correspondence/'.$records[2]->id.'/operations', ['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'action' => 'reopen', 'revision' => 2, 'note' => 'Reopen for synthetic review'])->assertOk();
        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.open_incoming', 3)->assertJsonPath('totals.closed', 0)->assertJsonPath('totals.overdue', 2);
    }

    public function test_aggregate_visibility_follows_document_metadata_grants_and_clearances(): void
    {
        [$record] = $this->fixture('incoming', 'highly_confidential');
        $reader = User::factory()->create();
        $site = Site::findOrFail($record->profile->site_id);
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        $this->grant($reader, $record->profile->documentScope()->authorizationScope(), ['documents.metadata.view']);
        $this->signIn($reader);

        $hidden = $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.total', 0)->assertJsonCount(0, 'recent')->json();
        $this->assertSame([], $hidden['trends']['monthly']);
        ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id]);
        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.total', 0);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.total', 1);
    }

    public function test_review_metrics_use_current_authoritative_revision_and_ignore_later_draft(): void
    {
        [$record, $actor] = $this->fixture('controlled');
        $this->travelTo('2026-10-30 12:00:00');
        $this->grant($actor, $record->profile->documentScope()->authorizationScope(), ['reports.view']);
        $scope = $record->profile->documentScope();
        $manager = app(ManageControlledDocuments::class);
        $record->revisions->first()->update(['review_date' => '2026-10-29']);
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'REPORT/CD', 'padding' => 6, 'active' => true]);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version]);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions->first()->id, 'reason' => 'Synthetic release']);
        $record = $manager->revise($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_reason' => 'Synthetic annual review', 'change_description' => 'Prepare future review']);
        $record->revisions->last()->update(['review_date' => '2027-10-30']);
        $this->getJson($this->reportUrl($record, 'reports', ['view' => 'revisions', 'register' => 'controlled']))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.total', 1)->assertJsonPath('summary.draft_revisions', 1);

        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.controlled', 1)->assertJsonPath('totals.authoritative', 1)->assertJsonPath('totals.draft_documents', 1)->assertJsonPath('totals.review_overdue', 1)->assertJsonPath('totals.review_soon', 0)->assertJsonCount(1, 'reviews');
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions->last()->id, 'reason' => 'Release reviewed procedure']);
        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.controlled', 1)->assertJsonPath('totals.authoritative', 1)->assertJsonPath('totals.draft_documents', 0)->assertJsonPath('totals.review_overdue', 0);
        $this->getJson($this->reportUrl($record, 'reports', ['view' => 'revisions', 'register' => 'controlled', 'revision_state' => 'superseded']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.revision_identifier', '00')->assertJsonPath('data.0.revision_status', 'superseded');
    }

    public function test_report_access_is_explicit_and_totals_reconcile_with_dashboard(): void
    {
        [$record, $actor] = $this->fixture();
        $this->getJson($this->reportUrl($record, 'reports'))->assertForbidden();
        $this->grant($actor, $record->profile->documentScope()->authorizationScope(), ['reports.view']);
        $report = $this->getJson($this->reportUrl($record, 'reports'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total', 1)->assertJsonCount(1, 'data')->json();
        $dashboard = $this->getJson($this->reportUrl($record))->assertOk()->json();
        $this->assertSame($dashboard['totals']['incoming'], $report['summary']['incoming']);
        $this->getJson($this->reportUrl($record, 'reports', ['date_from' => '2026-10-27']))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('summary.total', 0);
        $this->getJson($this->reportUrl($record, 'reports', ['sort' => 'id; DROP TABLE users']))->assertUnprocessable();
        $this->getJson($this->reportUrl($record, 'reports/export', ['format' => 'csv']))->assertUnprocessable();
    }

    #[TestWith(['en', 'xlsx'])]
    #[TestWith(['lo', 'xlsx'])]
    #[TestWith(['en', 'pdf'])]
    #[TestWith(['lo', 'pdf'])]
    public function test_authorized_management_exports_reuse_formats_and_record_access(string $language, string $format): void
    {
        [$record, $actor] = $this->fixture();
        $record->update(['subject' => '=HYPERLINK("https://example.test", "Synthetic")']);
        $this->grant($actor, $record->profile->documentScope()->authorizationScope(), ['reports.view']);
        $response = $this->get($this->reportUrl($record, 'reports/export', ['format' => $format, 'language' => $language, 'register' => 'incoming', 'date_from' => '2026-10-01']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'dcms-report-');
            try {
                file_put_contents($path, $response->getContent());
                $book = IOFactory::load($path);
                $sheet = $book->getActiveSheet();
                $this->assertSame('DCMS', $sheet->getTitle());
                $this->assertSame($record->subject, $sheet->getCell('C8')->getValue());
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C8')->getDataType());
                $this->assertSame('dd/mm/yyyy', $sheet->getCell('D8')->getStyle()->getNumberFormat()->getFormatCode());
                $this->assertNotEmpty($sheet->getAutoFilter()->getRange());
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        } else {
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertGreaterThanOrEqual(1, (int) $response->headers->get('X-PDF-Pages'));
        }
        $this->assertDatabaseHas('document_security_audits', ['actor_id' => $actor->id, 'profile_id' => $record->profile_id, 'event' => 'access.allowed', 'operation' => 'metadata']);
        $this->assertTrue(DocumentSecurityAudit::query()->where('actor_id', $actor->id)->where('after->surface', 'report.export.'.$format)->exists());
    }

    public function test_physical_reports_use_copy_grain_and_latest_inventory_observation(): void
    {
        [$record, $actor] = $this->fixture();
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['reports.view']);
        $custody = app(ManagePhysicalCustody::class);
        $location = $custody->location($actor, $scope, 'incoming', (string) $record->id, ['room' => 'Synthetic reporting room']);
        $copy = $custody->create($actor, $scope, 'incoming', (string) $record->id, null, ['identifier' => 'REPORT/ORIGINAL', 'kind' => 'original', 'location_id' => $location->id, 'reason' => 'Synthetic fixture']);
        $custody->change($actor, $scope, 'incoming', (string) $record->id, null, $copy->id, ['action' => 'inventory', 'lock_version' => 1, 'observation' => 'discrepancy', 'reason' => 'Synthetic discrepancy']);
        $this->getJson($this->reportUrl($record, 'reports', ['view' => 'physical', 'metric' => 'inventory_discrepancy']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total', 1)->assertJsonPath('summary.outstanding_discrepancies', 1)->assertJsonPath('data.0.room', 'Synthetic reporting room');
        $custody->change($actor, $scope, 'incoming', (string) $record->id, null, $copy->id, ['action' => 'inventory', 'lock_version' => $copy->refresh()->lock_version, 'observation' => 'verified', 'reason' => 'Synthetic verified check']);
        $this->getJson($this->reportUrl($record, 'reports', ['view' => 'physical', 'metric' => 'inventory_discrepancy']))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('summary.outstanding_discrepancies', 0);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    #[TestWith(['controlled'])]
    public function test_report_rows_counts_groups_and_exports_do_not_reveal_restricted_documents(string $register): void
    {
        [$record] = $this->fixture($register, 'highly_confidential');
        $reader = User::factory()->create();
        $scope = $record->profile->documentScope();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($scope->siteId))->create();
        $this->grant($reader, $scope->authorizationScope(), ['documents.metadata.view', 'reports.view']);
        $this->signIn($reader);
        $result = $this->getJson($this->reportUrl($record, 'reports', ['all_sites' => 1, 'all_departments' => 1, 'search' => $record->document_code ?? $record->subject]))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('summary.total', 0)->assertJsonCount(0, 'data')->json();
        $this->assertSame([], $result['trends']['category_en']);
        $response = $this->get($this->reportUrl($record, 'reports/export', ['format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dcms-empty-report-');
        try {
            file_put_contents($path, $response->getContent());
            $book = IOFactory::load($path);
            $this->assertStringContainsString('0', $book->getActiveSheet()->getCell('A5')->getValue());
            $this->assertSame('No authorized records match these filters.', $book->getActiveSheet()->getCell('A8')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_all_workspace_reporting_never_expands_beyond_exact_authorized_contexts(): void
    {
        [$record, $actor] = $this->fixture();
        $this->grant($actor, $record->profile->documentScope()->authorizationScope(), ['reports.view']);
        [$other] = $this->fixture('outgoing');
        $this->signIn($actor);

        $this->getJson($this->reportUrl($record, 'reports', ['all_sites' => 1, 'all_departments' => 1]))->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'scope');
        $this->getJson($this->reportUrl($other, 'reports'))->assertForbidden();
        $department = Department::factory()->create(['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
        $this->getJson($this->reportUrl($record, 'reports', ['department_id' => $department->id]))->assertForbidden();
    }
}
