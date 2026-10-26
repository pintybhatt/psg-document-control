<?php

namespace Tests\Feature;

use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\OutgoingRecipient;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $response = $this->getJson($this->url($kind, $site, parameters: ['search' => 'VISIBLE', 'page' => 2, 'per_page' => 1, 'language' => 'lo']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->assertDownload($kind.'-correspondence.xlsx');
        $this->artifact($kind.'-lo.xlsx', $response->getContent());
        $book = $this->workbook($response->getContent());
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame(19, $sheet->getHighestDataRow());
            $this->assertSame('ທະບຽນເອກະສານ'.($kind === 'incoming' ? 'ຂາເຂົ້າ' : 'ຂາອອກ'), $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString('ສະຖານທີ່ທົດສອບ', $sheet->getCell('A2')->getValue());
            $this->assertStringContainsString('26/10/2026', $sheet->getCell('A3')->getValue());
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
            $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE);
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

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_detail_pdf_rechecks_document_confidentiality_and_organization(string $kind): void
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
        $this->postJson($this->url($kind, $site, '/'.$visible.'/register'))->assertOk();
        $operation = $kind === 'incoming'
            ? ['action' => 'update', 'revision' => 0, 'due_date' => '2026-10-28', 'response_required' => true]
            : ['action' => 'dispatch', 'revision' => 0, 'dispatch_date' => '2026-10-26', 'dispatch_channel' => 'courier', 'dispatch_reference' => 'REVIEW/001'];
        $this->postJson($this->url($kind, $site, '/'.$visible.'/operations'), $operation)->assertOk();
        if ($kind === 'outgoing') {
            $recipientId = OutgoingRecipient::query()->where('correspondence_id', $visible)->sole()->id;
            $this->postJson($this->url($kind, $site, '/'.$visible.'/operations'), ['action' => 'delivery', 'revision' => 1, 'recipient_id' => $recipientId, 'delivery_date' => '2026-10-26', 'acknowledgment_date' => '2026-10-26', 'acknowledged_by' => 'Review recipient'])->assertOk();
        }

        $this->reader($site);
        $response = $this->getJson($this->url($kind, $site, '/'.$visible.'/pdf', ['language' => 'lo']))->assertOk()->assertDownload($kind.'-correspondence-'.$visible.'.pdf');
        $this->artifact($kind.'-detail-lo.pdf', $response->getContent());
        $this->assertGreaterThanOrEqual(1, $this->pdfPages($response->getContent()));
        $this->getJson($this->url($kind, $site, '/'.$secret.'/pdf'))->assertNotFound();
        $this->getJson($this->url($kind, Site::factory()->create(), '/'.$visible.'/pdf'))->assertForbidden();
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
