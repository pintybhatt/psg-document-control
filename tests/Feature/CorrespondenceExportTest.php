<?php

namespace Tests\Feature;

use App\Authorization\ManageNumbering;
use App\CorrespondenceReport;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentType;
use App\Models\IncomingOperationAudit;
use App\Models\NumberingSeries;
use App\Models\OrganizationMembership;
use App\Models\OutgoingOperationAudit;
use App\Models\OutgoingRecipient;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class CorrespondenceExportTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, Site, DocumentType} */
    private function fixture(string $kind): array
    {
        $this->definitions();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        config(['app.debug' => false]);
        $administrator = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $administrator->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id, 'active' => true]);
        $site = Site::factory()->create(['name_en' => 'Review site', 'name_lo' => 'ສະຖານທີ່ທົດສອບ']);
        $site->company->update(['name_en' => 'XPPL review company', 'name_lo' => 'ບໍລິສັດທົດສອບ']);
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => $kind]);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id, 'name_en' => 'Letter', 'name_lo' => 'ຈົດໝາຍ']);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $this->signIn($administrator);

        return [$administrator, $site, $type];
    }

    private function draft(string $kind, Site $site, DocumentType $type, array $extra = []): int
    {
        $body = ['company_id' => $site->company_id, 'site_id' => $site->id, 'type_id' => $type->id, 'subject' => 'Visible correspondence', 'priority' => 'normal', 'classification' => 'normal'];
        $body += $kind === 'incoming'
            ? ['sender_organization' => 'Example sender', 'channel' => 'email', 'correspondence_date' => '2026-04-03', 'received_date' => '2026-10-26']
            : ['signatory_name' => 'Example signatory', 'document_date' => '2026-04-03', 'recipients' => [['kind' => 'primary', 'organization' => 'Example recipient']]];

        return $this->postJson('/api/v1/'.$kind.'-correspondence', array_replace($body, $extra))->assertCreated()->json('data.id');
    }

    private function registerRecord(string $kind, Site $site, int $id): array
    {
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $kind)->sole();
        foreach ($kind === 'incoming' ? ['internal_register'] : ['internal_register', 'official_outgoing'] as $purpose) {
            if (! NumberingSeries::query()->where('register_id', $register->id)->where('purpose', $purpose)->exists()) {
                app(ManageNumbering::class)->save(auth()->user(), $scope, ['register_id' => $register->id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => strtoupper($kind).'/'.strtoupper($purpose), 'padding' => 6, 'active' => true]);
            }
        }

        return $this->postJson($this->url($kind, $site, '/'.$id.'/register'))->assertOk()->json('data');
    }

    private function reader(Site $site): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, AuthorizationScope::context('site', $site->company_id, $site->id), ['documents.metadata.view']);
        $this->signIn($user);

        return $user;
    }

    private function url(string $kind, Site $site, string $suffix = '/export', array $parameters = []): string
    {
        return '/api/v1/'.$kind.'-correspondence'.$suffix.'?'.http_build_query($parameters + ['company_id' => $site->company_id, 'site_id' => $site->id]);
    }

    private function workbook(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'dcms-xlsx-');
        try {
            file_put_contents($path, $bytes);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function pdfPages(string $bytes): int
    {
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $this->assertStringContainsString('/FontFile2', $bytes);

        return (new PdfReader(new PdfParser(StreamReader::createByString($bytes))))->getPageCount();
    }

    private function artifact(string $name, string $bytes): void
    {
        if ($directory = getenv('DCMS_EXPORT_REVIEW_DIR')) {
            file_put_contents($directory.'/'.$name, $bytes);
        }
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_xlsx_contains_complete_filtered_authorized_data_dates_and_safe_text(string $kind): void
    {
        [, $site, $type] = $this->fixture($kind);
        for ($index = 0; $index < 12; $index++) {
            $this->draft($kind, $site, $type, ['subject' => '=VISIBLE '.$index.' ເອກະສານທົດສອບ']);
        }
        $this->draft($kind, $site, $type, ['subject' => 'VISIBLE SECRET', 'classification' => 'confidential']);
        $this->draft($kind, $site, $type, ['subject' => 'Other search term']);
        $department = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
        $this->draft($kind, $site, $type, ['subject' => 'VISIBLE OTHER DEPARTMENT', 'department_id' => $department->id]);
        $reader = $this->reader($site);
        $this->travelTo('2026-10-26 18:35:00');
        $response = $this->getJson($this->url($kind, $site, parameters: ['search' => 'VISIBLE', 'page' => 2, 'per_page' => 1, 'language' => 'lo']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->assertDownload($kind.'-correspondence.xlsx');
        $this->artifact($kind.'-lo.xlsx', $response->getContent());
        $book = $this->workbook($response->getContent());
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame(19, $sheet->getHighestDataRow());
            $this->assertSame('ທະບຽນເອກະສານ'.($kind === 'incoming' ? 'ຂາເຂົ້າ' : 'ຂາອອກ'), $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString('ສະຖານທີ່ທົດສອບ', $sheet->getCell('A2')->getValue());
            $this->assertStringContainsString('27/10/2026, 01:35', $sheet->getCell('A3')->getValue());
            $this->assertStringNotContainsString('UTC', $sheet->getCell('A3')->getValue());
            $this->assertStringContainsString('VISIBLE', $sheet->getCell('A4')->getValue());
            $subjectCell = $kind === 'incoming' ? 'B8' : 'C8';
            $this->assertSame('=VISIBLE 11 ເອກະສານທົດສອບ', $sheet->getCell($subjectCell)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($subjectCell)->getDataType());
            $dateCell = $kind === 'incoming' ? 'D8' : 'E8';
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($dateCell)->getDataType());
            $this->assertSame($kind === 'incoming' ? '26/10/2026' : '03/04/2026', $sheet->getCell($dateCell)->getFormattedValue());
            $this->assertSame('C8', $sheet->getFreezePane());
            $this->assertTrue($sheet->getStyle($subjectCell)->getAlignment()->getWrapText());
            $this->assertSame([1, 7], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString('SECRET', $text);
            $this->assertStringNotContainsString('OTHER DEPARTMENT', $text);
            $this->assertStringNotContainsString('Other search term', $text);
        } finally {
            $book->disconnectWorksheets();
        }
        $this->assertDatabaseHas('document_security_audits', ['actor_id' => $reader->id, 'event' => 'access.denied', 'reason' => 'clearance_denied']);
        $this->assertSame(12, DocumentSecurityAudit::query()->where('actor_id', $reader->id)->where('event', 'access.allowed')->count());
    }

    #[TestWith(['incoming', 'en'])]
    #[TestWith(['outgoing', 'lo'])]
    public function test_pdf_register_is_complete_multipage_a4_with_embedded_lao_fonts(string $kind, string $language): void
    {
        [, $site, $type] = $this->fixture($kind);
        for ($index = 0; $index < 35; $index++) {
            $this->draft($kind, $site, $type, ['subject' => 'Review '.$index.' · ເອກະສານທົດສອບ '.str_repeat('correspondence ', 5)]);
        }
        $response = $this->getJson($this->url($kind, $site, parameters: ['format' => 'pdf', 'search' => 'Review', 'page' => 3, 'per_page' => 1, 'language' => $language]))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload($kind.'-correspondence.pdf');
        $bytes = $response->getContent();
        $this->artifact($kind.'-'.$language.'.pdf', $bytes);
        $pageCount = $this->pdfPages($bytes);
        $this->assertGreaterThan(1, $pageCount);
        $this->assertSame((string) $pageCount, $response->headers->get('X-PDF-Pages'));
        $page = (new PdfReader(new PdfParser(StreamReader::createByString($bytes))))->getPage(1)->getBoundary();
        $this->assertEqualsWithDelta(841.89, $page->getWidth(), 1);
        $this->assertEqualsWithDelta(595.28, $page->getHeight(), 1);
    }

    #[TestWith(['incoming', 'xlsx'])]
    #[TestWith(['incoming', 'pdf'])]
    #[TestWith(['outgoing', 'xlsx'])]
    #[TestWith(['outgoing', 'pdf'])]
    public function test_export_rejects_cross_scope_and_missing_metadata_permission(string $kind, string $format): void
    {
        [, $site] = $this->fixture($kind);
        $other = Site::factory()->create();
        $user = $this->reader($site);

        $this->getJson($this->url($kind, $other, parameters: ['format' => $format]))->assertForbidden();
        $this->getJson($this->url($kind, $site, parameters: ['format' => $format, 'company_id' => $other->company_id]))->assertUnprocessable();
        $role = UserRoleAssignment::query()->where('user_id', $user->id)->sole()->role;
        $user->roleAssignments()->where('role_id', $role->id)->update(['active' => false]);
        $this->getJson($this->url($kind, $site, parameters: ['format' => $format]))->assertForbidden();
    }

    #[TestWith(['incoming', 'en'])]
    #[TestWith(['outgoing', 'lo'])]
    public function test_detail_pdf_rechecks_document_confidentiality_and_organization(string $kind, string $language): void
    {
        [, $site, $type] = $this->fixture($kind);
        $visible = $this->draft($kind, $site, $type, ['subject' => 'Detail <img src="file:///etc/passwd"> ທົດສອບ', 'description_lo' => 'ລາຍລະອຽດເອກະສານ']);
        $secret = $this->draft($kind, $site, $type, ['subject' => 'SECRET', 'classification' => 'highly_confidential']);
        $administrator = User::query()->findOrFail(auth()->id());
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->sole();
        foreach ($kind === 'incoming' ? ['internal_register'] : ['internal_register', 'official_outgoing'] as $purpose) {
            app(ManageNumbering::class)->save($administrator, $scope, ['register_id' => $register->id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => 'REVIEW/'.strtoupper(str_replace('_', '-', $purpose)), 'padding' => 6, 'active' => true]);
        }
        $this->travelTo('2026-10-26 18:35:00');
        $this->postJson($this->url($kind, $site, '/'.$visible.'/register'))->assertOk()->assertJsonPath('data.registered_at', '2026-10-26T18:35:00.000000Z');
        $this->postJson($this->url($kind, $site, '/'.$visible.'/operations'), ['action' => 'correct_details', 'revision' => 0, 'note' => 'Correct transcription for export review', 'details' => [
            $kind === 'incoming' ? 'correspondence_date' : 'document_date' => '2026-04-04',
        ]])->assertOk();
        $operation = $kind === 'incoming'
            ? ['action' => 'update', 'revision' => 1, 'due_date' => '2026-10-28', 'response_required' => true]
            : ['action' => 'dispatch', 'revision' => 1, 'dispatch_date' => '2026-10-26', 'dispatch_channel' => 'courier', 'dispatch_reference' => 'REVIEW/001'];
        $this->postJson($this->url($kind, $site, '/'.$visible.'/operations'), $operation)->assertOk();
        if ($kind === 'outgoing') {
            $recipientId = OutgoingRecipient::query()->where('correspondence_id', $visible)->sole()->id;
            $this->postJson($this->url($kind, $site, '/'.$visible.'/operations'), ['action' => 'delivery', 'revision' => 2, 'recipient_id' => $recipientId, 'delivery_date' => '2026-10-26', 'acknowledgment_date' => '2026-10-26', 'acknowledged_by' => 'Review recipient'])->assertOk();
        }

        $rendered = [];
        View::composer('correspondence-report', function (\Illuminate\View\View $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->reader($site);
        $response = $this->getJson($this->url($kind, $site, '/'.$visible.'/pdf', ['language' => $language]))->assertOk()->assertDownload($kind.'-correspondence-'.$visible.'.pdf');
        $this->artifact($kind.'-detail-'.$language.'.pdf', $response->getContent());
        $this->assertSame('27/10/2026, 01:35', $rendered['generated']);
        $report = app(CorrespondenceReport::class);
        $fields = collect($rendered['sections'][0]['fields']);
        $this->assertSame('27/10/2026, 01:35', $fields->firstWhere('label', $report->label('registered_at', $language))['value']);
        $this->assertSame('04/04/2026', $fields->firstWhere('label', $report->label($kind === 'incoming' ? 'correspondence_date' : 'document_date', $language))['value']);
        $registration = collect($rendered['sections'])->firstWhere('title', $report->label('registration_history', $language));
        $this->assertStringContainsString('27/10/2026, 01:35', $registration['fields'][1]['value']);
        $history = collect($rendered['sections'])->first(fn (array $section): bool => str_starts_with($section['title'], $report->label('history', $language).' · '));
        $this->assertStringContainsString('27/10/2026, 01:35', $history['title']);
        $this->assertStringContainsString($report->label('correct_details', $language), $history['title']);
        $this->assertSame('Correct transcription for export review', $history['text']);
        $this->assertStringContainsString('03/04/2026', $history['fields'][0]['value']);
        $this->assertStringContainsString('04/04/2026', $history['fields'][0]['value']);
        $this->assertStringNotContainsString('UTC', json_encode($rendered['sections'], JSON_UNESCAPED_UNICODE));
        $this->getJson($this->url($kind, $site, '/'.$visible))->assertOk()->assertJsonPath('data.registered_at', '2026-10-26T18:35:00.000000Z');
        $this->assertDatabaseHas($kind === 'incoming' ? 'incoming_correspondences' : 'outgoing_correspondences', ['id' => $visible, 'registered_at' => '2026-10-26 18:35:00']);
        $this->assertGreaterThanOrEqual(1, $this->pdfPages($response->getContent()));
        $this->getJson($this->url($kind, $site, '/'.$secret.'/pdf'))->assertNotFound();
        $this->getJson($this->url($kind, Site::factory()->create(), '/'.$visible.'/pdf'))->assertForbidden();
    }

    #[TestWith(['incoming', null])]
    #[TestWith(['outgoing', null])]
    #[TestWith(['incoming', '01'])]
    #[TestWith(['outgoing', 'Rev A'])]
    public function test_manual_revision_drafts_are_optional_editable_and_limited(string $kind, ?string $revision): void
    {
        [, $site, $type] = $this->fixture($kind);
        $id = $this->draft($kind, $site, $type, $revision === null ? [] : ['document_revision' => $revision]);
        $record = $this->getJson($this->url($kind, $site, '/'.$id))->assertOk()->assertJsonPath('data.document_revision', $revision)->json('data');
        $this->assertDatabaseHas($kind.'_correspondences', ['id' => $id, 'document_revision' => $revision]);
        $body = array_intersect_key($record, array_flip(['type_id', 'subject', 'sender_organization', 'channel', 'correspondence_date', 'received_date', 'signatory_name', 'document_date', 'recipients', 'priority']));
        if ($kind === 'outgoing') {
            $body['recipients'] = [['kind' => 'primary', 'organization' => 'Example recipient']];
        }
        $this->patchJson($this->url($kind, $site, '/'.$id), $body + ['document_revision' => 'R00'])->assertOk()->assertJsonPath('data.document_revision', 'R00');
        $this->patchJson($this->url($kind, $site, '/'.$id), $body + ['document_revision' => str_repeat('A', 31)])->assertUnprocessable()->assertJsonValidationErrors('document_revision');
        $this->patchJson($this->url($kind, $site, '/'.$id), $body + ['document_revision' => null])->assertOk()->assertJsonPath('data.document_revision', null);
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_registered_manual_revision_corrections_are_authorized_audited_and_exported(string $kind): void
    {
        [$actor, $site, $type] = $this->fixture($kind);
        $id = $this->draft($kind, $site, $type);
        $identity = $this->registerRecord($kind, $site, $id);
        $url = $this->url($kind, $site, '/'.$id.'/operations');
        $payload = ['action' => 'correct_details', 'revision' => 0, 'note' => 'Revision transcribed from original', 'details' => ['document_revision' => '01']];
        $this->postJson($url, array_replace($payload, ['note' => null]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['details' => ['document_revision' => str_repeat('A', 31)]]))->assertUnprocessable()->assertJsonValidationErrors('details.document_revision');
        $this->reader($site);
        $this->postJson($url, $payload)->assertForbidden();
        $this->signIn($actor);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.document_revision', '01')->assertJsonPath('data.operations.revision', 1)->assertJsonPath('data.internal_number', $identity['internal_number']);
        $this->getJson($this->url($kind, $site, '/'.$id))->assertOk()->assertJsonPath('history.0.before.details.document_revision', null)->assertJsonPath('history.0.after.details.document_revision', '01');
        $this->assertDatabaseHas($kind.'_correspondences', ['id' => $id, 'document_revision' => '01']);
        $book = $this->workbook($this->getJson($this->url($kind, $site))->assertOk()->getContent());
        try {
            $sheet = $book->getActiveSheet();
            $headers = $sheet->rangeToArray('A7:'.$sheet->getHighestDataColumn().'7')[0];
            $column = array_search('Revision', $headers, true) + 1;
            $this->assertSame('01', $sheet->getCell([$column, 8])->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell([$column, 8])->getDataType());
            $this->assertContains('Document category', $headers);
            $this->assertContains('Registration date', $headers);
            $this->assertContains('Operational status', $headers);
        } finally {
            $book->disconnectWorksheets();
        }
        $rendered = [];
        View::composer('correspondence-report', function (\Illuminate\View\View $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->getJson($this->url($kind, $site, parameters: ['format' => 'pdf']))->assertOk();
        $this->assertSame('01', $rendered['rows'][0]['document_revision']);
        $this->postJson($url, array_replace($payload, ['revision' => 1, 'details' => ['document_revision' => null]]))->assertOk()->assertJsonPath('data.document_revision', null);
        $this->postJson($url, ['action' => 'close', 'revision' => 2, 'note' => 'Handling completed'])->assertOk();
        $this->postJson($url, array_replace($payload, ['revision' => 3]))->assertUnprocessable();
        $this->getJson($this->url($kind, $site, '/'.$id.'/pdf'))->assertOk();
        $status = collect($rendered['sections'][0]['fields'])->firstWhere('label', 'Operational status');
        $this->assertSame('Closed', $status['value']);
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_revision_migration_preserves_existing_registered_records_with_null_revision(string $kind): void
    {
        [, $site, $type] = $this->fixture($kind);
        $id = $this->draft($kind, $site, $type);
        $this->registerRecord($kind, $site, $id);
        $migration = require database_path('migrations/2026_11_03_085847_add_document_revision_to_correspondences.php');
        $migration->down();
        $before = (array) DB::table($kind.'_correspondences')->where('id', $id)->first();
        $migration->up();
        $after = (array) DB::table($kind.'_correspondences')->where('id', $id)->first();
        $this->assertNull($after['document_revision']);
        unset($after['document_revision']);
        $this->assertSame($before, $after);
        $this->getJson($this->url($kind, $site, '/'.$id))->assertOk()->assertJsonPath('data.document_revision', null);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_manual_revision_database_guard_rejects_a_correction_audit_for_other_fields(string $kind): void
    {
        [$actor, $site, $type] = $this->fixture($kind);
        $id = $this->draft($kind, $site, $type);
        $this->registerRecord($kind, $site, $id);
        $auditClass = $kind === 'incoming' ? IncomingOperationAudit::class : OutgoingOperationAudit::class;
        $audit = $auditClass::query()->create(['correspondence_id' => $id, 'actor_id' => $actor->id, 'revision' => 1, 'action' => $kind === 'incoming' ? 'updated' : 'note',
            'note' => 'Correct subject transcription', 'before' => ['details' => ['subject' => 'Visible correspondence']], 'after' => ['details' => ['subject' => 'Changed subject']]]);
        try {
            DB::table($kind.'_correspondences')->where('id', $id)->update(['subject' => 'Changed subject', 'document_revision' => 'BYPASS', 'updated_by' => $actor->id, 'updated_at' => $audit->created_at]);
            $this->fail('An unrelated correction audit allowed a revision change.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Protected correspondence revision', $exception->getMessage());
        }
        $this->assertDatabaseHas($kind.'_correspondences', ['id' => $id, 'document_revision' => null, 'subject' => 'Visible correspondence']);
    }

    public function test_incoming_details_and_exports_include_only_authorized_outgoing_links_and_existing_physical_references(): void
    {
        [$actor, $site, $type] = $this->fixture('incoming');
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $incoming = $this->draft('incoming', $site, $type);
        $incomingRecord = $this->registerRecord('incoming', $site, $incoming);
        $visible = $this->draft('outgoing', $site, $type, ['subject' => 'Visible reply']);
        $secret = $this->draft('outgoing', $site, $type, ['subject' => 'Secret reply', 'classification' => 'confidential']);
        $visibleRecord = $this->registerRecord('outgoing', $site, $visible);
        $secretRecord = $this->registerRecord('outgoing', $site, $secret);
        foreach ([$visible, $secret] as $outgoing) {
            $this->postJson($this->url('outgoing', $site, '/'.$outgoing.'/operations'), ['action' => 'link', 'revision' => 0, 'incoming_id' => $incoming])->assertOk();
        }
        $storage = '/api/v1/document-storage/incoming/'.$incoming;
        $parameters = '?'.http_build_query(['company_id' => $site->company_id, 'site_id' => $site->id]);
        $location = $this->postJson($storage.'/locations'.$parameters, ['room' => 'Archive', 'cabinet' => 'A', 'shelf' => '2', 'box' => '001'])->assertCreated()->json('data.id');
        $this->postJson($storage.'/copies'.$parameters, ['identifier' => 'ORIGINAL-001', 'kind' => 'original', 'location_id' => $location, 'reason' => 'Physical original retained'])->assertCreated();
        $this->postJson('/api/v1/document-storage/outgoing/'.$visible.'/copies'.$parameters, ['identifier' => 'OUTGOING-ORIGINAL-001', 'kind' => 'original', 'location_id' => $location, 'reason' => 'Physical outgoing original retained'])->assertCreated();
        $this->reader($site);
        $this->getJson($this->url('incoming', $site, '/'.$incoming))->assertOk()->assertJsonCount(1, 'related_outgoing')->assertJsonPath('related_outgoing.0.subject', 'Visible reply')->assertJsonPath('related_outgoing.0.official_number', $visibleRecord['official_number']);
        $book = $this->workbook($this->getJson($this->url('incoming', $site))->assertOk()->getContent());
        try {
            $text = json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString($visibleRecord['official_number'], $text);
            $this->assertStringContainsString('ORIGINAL-001', $text);
            $this->assertStringContainsString('Archive → A → 2 → 001', $text);
            $this->assertStringNotContainsString('Secret reply', $text);
            $this->assertStringNotContainsString($secretRecord['official_number'], $text);
        } finally {
            $book->disconnectWorksheets();
        }
        $rendered = [];
        View::composer('correspondence-report', function (\Illuminate\View\View $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->getJson($this->url('incoming', $site, parameters: ['format' => 'pdf']))->assertOk();
        $this->assertSame($visibleRecord['official_number'], $rendered['rows'][0]['related_references']);
        $this->assertStringContainsString('ORIGINAL-001', $rendered['rows'][0]['physical_references']);
        $this->getJson($this->url('incoming', $site, '/'.$incoming.'/pdf'))->assertOk();
        $text = json_encode($rendered['sections'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Visible reply', $text);
        $this->assertStringNotContainsString('Secret reply', $text);
        $this->assertStringNotContainsString($secretRecord['official_number'], $text);
        $this->getJson($this->url('outgoing', $site, parameters: ['format' => 'pdf']))->assertOk();
        $this->assertCount(1, $rendered['rows']);
        $this->assertSame($incomingRecord['internal_number'], $rendered['rows'][0]['related_references']);
        $this->assertStringContainsString('OUTGOING-ORIGINAL-001', $rendered['rows'][0]['physical_references']);
        $book = $this->workbook($this->getJson($this->url('outgoing', $site))->assertOk()->getContent());
        try {
            $text = json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString($incomingRecord['internal_number'], $text);
            $this->assertStringContainsString('OUTGOING-ORIGINAL-001', $text);
            $this->assertStringNotContainsString($secretRecord['official_number'], $text);
        } finally {
            $book->disconnectWorksheets();
        }
        $this->assertDatabaseCount('attachment_versions', 0);
    }

    public function test_export_requires_session_validates_format_and_returns_a_valid_empty_workbook(): void
    {
        $this->getJson('/api/v1/incoming-correspondence/export?company_id=1&site_id=1')->assertUnauthorized();
        [, $site] = $this->fixture('incoming');
        $this->getJson($this->url('incoming', $site, parameters: ['format' => 'csv', 'language' => 'unsupported']))->assertUnprocessable()->assertJsonValidationErrors(['format', 'language']);
        $response = $this->getJson($this->url('incoming', $site))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $book = $this->workbook($response->getContent());
        $this->assertSame('No correspondence matches these filters.', $book->getActiveSheet()->getCell('A8')->getValue());
        $book->disconnectWorksheets();
    }
}
