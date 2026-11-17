<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Authorization\XpplNumbering;
use App\Http\Resources\ControlledDocumentResource;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\ControlledDocument;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\OrganizationMembership;
use App\Models\OutgoingCorrespondence;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class XpplNumberingTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private User $actor;

    private Company $company;

    private AuthorizationScope $companyScope;

    private function setupCompany(): void
    {
        $this->definitions();
        $this->travelTo(now()->setDate(2026, 10, 26));
        $this->company = Company::factory()->create(['code' => 'XPPL']);
        $this->actor = User::factory()->create();
        $this->companyScope = AuthorizationScope::context('company', $this->company->id)->persist();
        OrganizationMembership::factory()->for($this->actor)->create(['company_id' => $this->company->id]);
        $this->grant($this->actor, $this->companyScope, ['numbering.view', 'numbering.manage', 'numbering.issue', 'documents.metadata.view',
            'controlled.drafts.manage', 'controlled.register', 'controlled.release', 'incoming.drafts.manage', 'incoming.register', 'outgoing.drafts.manage', 'outgoing.register']);
        $this->signIn($this->actor);
    }

    public function test_company_policy_initializes_independent_combinations_only_at_registration(): void
    {
        $this->setupCompany();
        $first = $this->site(withSeries: false);
        $second = $this->site('ENG', 'SDF', withSeries: false);
        $shared = $this->site(withSeries: false);
        $differentType = $this->site('ADM', 'SDF', withSeries: false);
        $body = $this->scopeData($this->companyScope);
        $this->postJson('/api/v1/numbering-series/xppl/activate', $body)->assertUnprocessable();
        $this->assertDatabaseCount('xppl_numbering_policies', 0);
        $this->postJson('/api/v1/numbering-series/xppl/activate', $body + ['confirm' => true])->assertOk();
        $this->postJson('/api/v1/numbering-series/xppl/activate', $body + ['confirm' => true])->assertOk();
        $this->assertDatabaseCount('xppl_numbering_policies', 1);
        $this->assertDatabaseCount('numbering_series', 0);
        $manager = app(ManageControlledDocuments::class);
        $this->assertSame('XPPL-ADM-SOP-0001-R00', $manager->automaticNumbering($first['scope'], $first['type_id'])['preview']);
        $draft = $this->draft($first);
        $this->assertNull($draft->document_code);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $registered = $this->issue($first, $draft, null);
        $this->assertSame('XPPL-ADM-SOP-0001', $registered->document_code);
        $this->assertSame($registered->id, $this->issue($first, $draft, null)->id);
        $this->assertSame('XPPL-ADM-SOP-0002', $this->issue($first, $this->draft($first), null)->document_code);
        $this->assertSame('XPPL-ENG-SDF-0001', $this->issue($second, $this->draft($second), null)->document_code);
        $this->assertSame('XPPL-ADM-SOP-0003', $this->issue($shared, $this->draft($shared), null)->document_code);
        $this->assertSame('XPPL-ADM-SDF-0001', $this->issue($differentType, $this->draft($differentType), null)->document_code);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 3);
        $this->assertDatabaseCount('xppl_numbering_allocations', 5);
    }

    /** @param array<string, mixed> $site */
    private function incomingDraft(array $site): IncomingCorrespondence
    {
        $scope = $site['scope']->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'incoming')->first();
        if (! $register) {
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
            $site['type']->registers()->attach($register->id, ['scope_id' => $scope->id]);
        }

        return app(ManageIncomingCorrespondence::class)->save($this->actor, $site['scope'], [
            'type_id' => $site['type_id'], 'classification' => 'normal', 'subject' => 'Incoming letter', 'sender_organization' => 'Sender',
            'external_reference' => 'SENDER/0042', 'correspondence_date' => today()->format('Y-m-d'), 'received_date' => today()->format('Y-m-d'),
            'priority' => 'normal', 'channel' => 'email',
        ]);
    }

    /** @param array<string, mixed> $site */
    private function outgoingDraft(array $site): OutgoingCorrespondence
    {
        return app(ManageOutgoingCorrespondence::class)->save($this->actor, $site['scope'], [
            'type_id' => $site['type_id'], 'classification' => 'normal', 'subject' => 'Outgoing letter', 'signatory_name' => 'Signatory',
            'document_date' => today()->format('Y-m-d'), 'priority' => 'normal', 'recipients' => [['kind' => 'primary', 'organization' => 'Recipient']],
        ]);
    }

    public function test_correspondence_policies_allocate_independent_company_and_department_annual_sequences(): void
    {
        $this->setupCompany();
        $in = $this->site('ADM', 'LIN', withSeries: false);
        $otherIn = $this->site('ADM', 'LIN', withSeries: false);
        $out = $this->site('ADM', 'LTR', true, false);
        $otherOut = $this->site('ENG', 'LTR', true, false);
        foreach (['incoming', 'outgoing'] as $kind) {
            $body = $this->scopeData($this->companyScope) + ['register_kind' => $kind];
            $this->postJson('/api/v1/numbering-series/xppl/activate', $body)->assertUnprocessable();
            $this->postJson('/api/v1/numbering-series/xppl/activate', $body + ['confirm' => true])->assertOk();
        }
        $this->assertDatabaseCount('xppl_numbering_policies', 0);
        $this->assertDatabaseCount('numbering_series', 0);
        $draft = $this->incomingDraft($in);
        $this->assertNull($draft->internal_number);
        $this->assertDatabaseCount('xppl_numbering_counters', 0);
        $incoming = app(ManageIncomingCorrespondence::class);
        $outgoing = app(ManageOutgoingCorrespondence::class);
        $first = $incoming->register($this->actor, $in['scope'], (string) $draft->id);
        $this->assertSame('XPPL-IN-2026-0001', $first->internal_number);
        $this->assertSame('SENDER/0042', $first->external_reference);
        $this->assertSame($first->id, $incoming->register($this->actor, $in['scope'], (string) $draft->id)->id);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertSame('XPPL-IN-2026-0002', $incoming->register($this->actor, $in['scope'], (string) $this->incomingDraft($in)->id)->internal_number);
        $this->assertSame('XPPL-IN-2026-0003', $incoming->register($this->actor, $otherIn['scope'], (string) $this->incomingDraft($otherIn)->id)->internal_number);
        $letterDraft = $this->outgoingDraft($out);
        $this->assertNull($letterDraft->internal_number);
        $letter = $outgoing->register($this->actor, $out['scope'], (string) $letterDraft->id);
        $this->assertSame('XPPL-OUT-2026-0001', $letter->internal_number);
        $this->assertSame('XPPL-ADM-LTR-26-0001', $letter->official_number);
        $this->assertSame($letter->id, $outgoing->register($this->actor, $out['scope'], (string) $letterDraft->id)->id);
        $other = $outgoing->register($this->actor, $otherOut['scope'], (string) $this->outgoingDraft($otherOut)->id);
        $this->assertSame('XPPL-OUT-2026-0002', $other->internal_number);
        $this->assertSame('XPPL-ENG-LTR-26-0001', $other->official_number);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('XPPL-IN-2027-0001', $incoming->register($this->actor, $in['scope'], (string) $this->incomingDraft($in)->id)->internal_number);
        $nextYear = $outgoing->register($this->actor, $out['scope'], (string) $this->outgoingDraft($out)->id);
        $this->assertSame('XPPL-OUT-2027-0001', $nextYear->internal_number);
        $this->assertSame('XPPL-ADM-LTR-27-0001', $nextYear->official_number);
        $this->assertSame('XPPL-IN-2026-0001', $first->fresh()->internal_number);
        $this->assertDatabaseCount('issued_document_numbers', 10);
        $this->assertDatabaseCount('xppl_numbering_allocations', 10);
    }

    public function test_outgoing_policy_rollback_leaves_no_partial_numbers_or_initialization(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'LTR', true, false);
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
        $draft = $this->outgoingDraft($site);
        DB::beginTransaction();
        app(ManageOutgoingCorrespondence::class)->register($this->actor, $site['scope'], (string) $draft->id);
        DB::rollBack();
        $this->assertNull($draft->fresh()->internal_number);
        $this->assertNull($draft->fresh()->official_number);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->assertDatabaseCount('numbering_series', 0);
        $retry = app(ManageOutgoingCorrespondence::class)->register($this->actor, $site['scope'], (string) $draft->id);
        $this->assertSame('XPPL-OUT-2026-0001', $retry->internal_number);
        $this->assertSame('XPPL-ADM-LTR-26-0001', $retry->official_number);
    }

    public function test_official_letter_lookup_and_explicit_lot_draft_correction_preserve_document_content(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'LOT', true, false);
        $draft = $this->outgoingDraft($site);
        $recipientIds = $draft->recipients()->pluck('id')->all();
        $profileId = $draft->profile_id;
        $unit = $site['scope'];
        $scope = $unit->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'outgoing')->sole();
        $ltr = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $site['type']->category_id, 'code' => 'LTR']);
        $ltr->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $this->grant($this->actor, $scope, ['documents.attachments.manage', 'documents.content.preview', 'documents.attachments.download']);
        Storage::fake('documents');
        $query = '?'.http_build_query(['company_id' => $unit->companyId, 'site_id' => $unit->siteId, 'department_id' => $unit->departmentId]);
        $attachment = $this->postJson('/api/v1/document-storage/outgoing/'.$draft->id.'/attachments'.$query, [
            'file' => UploadedFile::fake()->createWithContent('existing.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'reason' => 'Existing draft attachment',
        ])->assertCreated()->json('data.id');
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
        $this->getJson('/api/v1/outgoing-correspondence/lookups'.$query)->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $ltr->id, 'code' => 'LTR']);
        $body = ['type_id' => $ltr->id, 'subject' => $draft->subject, 'signatory_name' => $draft->signatory_name,
            'document_date' => $draft->document_date->format('Y-m-d'), 'priority' => $draft->priority,
            'recipients' => $draft->recipients->map(fn ($recipient): array => $recipient->only(['kind', 'organization', 'contact_name', 'email', 'phone', 'address']))->all()];
        $this->postJson('/api/v1/outgoing-correspondence'.$query, array_replace($body, ['type_id' => $site['type_id'], 'classification' => 'normal']))->assertCreated()->assertJsonPath('data.type_id', $site['type_id']);
        $ltr->registers()->detach($register->id);
        $this->patchJson('/api/v1/outgoing-correspondence/'.$draft->id.$query, $body)->assertUnprocessable()->assertJsonValidationErrors('type_id');
        $ltr->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $this->patchJson('/api/v1/outgoing-correspondence/'.$draft->id.$query, $body)->assertOk()->assertJsonPath('data.type_id', $ltr->id)->assertJsonPath('data.id', $draft->id);
        $this->assertSame($profileId, $draft->fresh()->profile_id);
        $this->assertSame($recipientIds, $draft->recipients()->pluck('id')->all());
        $this->assertDatabaseHas('document_attachments', ['id' => $attachment, 'profile_id' => $profileId]);
        $this->assertDatabaseCount('attachment_versions', 1);
        $this->assertSame(['created', 'updated'], $draft->audits()->orderBy('id')->pluck('action')->all());
        $this->postJson('/api/v1/outgoing-correspondence/'.$draft->id.'/register'.$query)->assertOk()
            ->assertJsonPath('data.internal_number', 'XPPL-OUT-2026-0001')->assertJsonPath('data.official_number', 'XPPL-ADM-LTR-26-0001');
        $this->postJson('/api/v1/outgoing-correspondence/'.$draft->id.'/register'.$query)->assertOk();
        $this->assertDatabaseCount('issued_document_numbers', 2);
        $this->patchJson('/api/v1/outgoing-correspondence/'.$draft->id.$query, array_replace($body, ['type_id' => $site['type_id']]))->assertUnprocessable();
        $this->assertSame($ltr->id, $draft->fresh()->type_id);
    }

    public function test_outgoing_policy_registers_reports_and_drawings_with_shared_official_numbers_and_preserves_letters(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'RPT', true, false);
        $scope = $site['scope']->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'outgoing')->sole();
        $incoming = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $controlled = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'controlled']);
        $site['type']->registers()->attach([$incoming->id, $controlled->id], ['scope_id' => $scope->id]);
        $letter = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $site['type']->category_id, 'code' => 'LTR']);
        $letter->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $inactive = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $site['type']->category_id, 'active' => false]);
        $inactive->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $incomingOnly = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $site['type']->category_id]);
        $incomingOnly->registers()->attach($incoming->id, ['scope_id' => $scope->id]);
        $this->site('ENG', 'RPT', true, false);
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
        $query = '?'.http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'department_id' => $site['department_id']]);

        $this->getJson('/api/v1/outgoing-correspondence/lookups'.$query)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $site['type_id'], 'code' => 'RPT'])->assertJsonFragment(['id' => $letter->id, 'code' => 'LTR']);
        $this->getJson('/api/v1/incoming-correspondence/lookups'.$query)->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $site['type_id']]);
        $this->getJson('/api/v1/controlled-documents/lookups'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $site['type_id']);
        $body = ['type_id' => $site['type_id'], 'classification' => 'normal', 'subject' => 'Outgoing report', 'signatory_name' => 'Signatory',
            'document_date' => '2026-10-26', 'priority' => 'normal', 'recipients' => [['kind' => 'primary', 'organization' => 'Recipient']]];
        $id = $this->postJson('/api/v1/outgoing-correspondence'.$query, $body)->assertCreated()->assertJsonPath('data.type_id', $site['type_id'])->json('data.id');
        unset($body['classification']);
        $this->patchJson('/api/v1/outgoing-correspondence/'.$id.$query, $body)->assertOk()->assertJsonPath('data.type_id', $site['type_id']);
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $id, 'type_id' => $site['type_id'], 'status' => 'draft', 'internal_number' => null, 'official_number' => null]);
        $this->postJson('/api/v1/outgoing-correspondence/'.$id.'/register'.$query)->assertUnprocessable()->assertJsonPath('errors.outgoing.0', 'outgoingOfficialNumberingUnavailable');
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        app(ManageNumbering::class)->save($this->actor, $scope, ['register_id' => $register->id, 'purpose' => 'official_outgoing',
            'reset_policy' => 'annual', 'prefix' => 'XPPL-OUT', 'padding' => 4, 'format' => ['pattern' => '{prefix}-{year2}-{sequence}'], 'active' => true]);
        DB::beginTransaction();
        app(ManageOutgoingCorrespondence::class)->register($this->actor, $site['scope'], (string) $id);
        DB::rollBack();
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $id, 'status' => 'draft', 'official_number' => null, 'internal_number' => null]);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->postJson('/api/v1/outgoing-correspondence/'.$id.'/register'.$query)->assertOk()
            ->assertJsonPath('data.official_number', 'XPPL-OUT-26-0001')->assertJsonPath('data.internal_number', 'XPPL-OUT-2026-0001');
        $this->postJson('/api/v1/outgoing-correspondence/'.$id.'/register'.$query)->assertOk()->assertJsonPath('data.official_number', 'XPPL-OUT-26-0001');
        $letterRecord = app(ManageOutgoingCorrespondence::class)->register($this->actor, $site['scope'], (string) $this->outgoingDraft(array_replace($site, ['type_id' => $letter->id]))->id);
        $this->assertSame('XPPL-ADM-LTR-26-0001', $letterRecord->official_number);
        $drawing = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $site['type']->category_id, 'code' => 'DRW']);
        $drawing->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $drawingDraft = $this->outgoingDraft(array_replace($site, ['type_id' => $drawing->id]));
        $this->postJson('/api/v1/outgoing-correspondence/'.$drawingDraft->id.'/register'.$query)->assertOk()
            ->assertJsonPath('data.official_number', 'XPPL-OUT-26-0003')->assertJsonPath('data.internal_number', 'XPPL-OUT-2026-0003');
        $this->assertSame('XPPL-ADM-LTR-26-0001', $letterRecord->fresh()->official_number);
        $this->assertDatabaseCount('issued_document_numbers', 6);
        $this->assertDatabaseCount('xppl_numbering_allocations', 4);
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $drawingDraft->id, 'official_number' => 'XPPL-OUT-26-0003', 'type_id' => $drawing->id]);
    }

    #[TestWith(['department'])]
    #[TestWith(['type'])]
    public function test_outgoing_policy_requires_eligible_type_and_origin_before_initialization(string $missing): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', $missing === 'type' ? 'MEM' : 'LTR', true, false);
        if ($missing === 'department') {
            $site['scope'] = new DocumentScope($this->company->id, $site['scope']->siteId);
        }
        $draft = $this->outgoingDraft($site);
        if ($missing === 'type') {
            $site['type']->update(['active' => false]);
        }
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
        try {
            app(ManageOutgoingCorrespondence::class)->register($this->actor, $site['scope'], (string) $draft->id);
            $this->fail('Outgoing registration prerequisites must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertSame($missing === 'type' ? ['type_id' => ['outgoingInvalidType']] : ['outgoing' => ['outgoingXpplOriginRequired']], $exception->errors());
        }
        $this->assertDatabaseCount('numbering_series', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_correspondence_activation_history_prevents_destructive_migration_rollback(): void
    {
        $this->setupCompany();
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'incoming', 'confirm' => true]);
        $migration = require database_path('migrations/2026_11_03_072914_extend_xppl_numbering_for_correspondence.php');
        try {
            $migration->down();
            $this->fail('Activated correspondence policies cannot be removed by rollback.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('forward-only repair', $exception->getMessage());
        }
        $this->assertDatabaseHas('xppl_correspondence_policies', ['company_id' => $this->company->id, 'register_kind' => 'incoming']);
    }

    public function test_outgoing_policy_preserves_existing_letter_allocations_and_series_configuration(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'LTR', true);
        $namespace = $this->enable($site);
        $manager = app(ManageOutgoingCorrespondence::class);
        $old = $manager->register($this->actor, $site['scope'], (string) $this->outgoingDraft($site)->id, $namespace);
        $oldInternal = $old->internal_number;
        $configurations = DB::table('numbering_configurations')->get()->toArray();
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
        $new = $manager->register($this->actor, $site['scope'], (string) $this->outgoingDraft($site)->id);
        $this->assertSame('XPPL-OUT-2026-0001', $new->internal_number);
        $this->assertSame('XPPL-ADM-LTR-26-0002', $new->official_number);
        $this->assertSame('XPPL-ADM-LTR-26-0001', $old->fresh()->official_number);
        $this->assertSame($oldInternal, $old->fresh()->internal_number);
        $this->assertEquals($configurations, DB::table('numbering_configurations')->get()->toArray());
        $this->assertSame([2, 2], DB::table('numbering_counters')->orderBy('series_id')->pluck('value')->map(fn ($value): int => (int) $value)->all());
    }

    public function test_correspondence_policy_does_not_bypass_activation_or_registration_authority(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'LIN', withSeries: false);
        $draft = $this->incomingDraft($site);
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($site['scope']->siteId))->create();
        OrganizationMembership::factory()->for($reader)->inDepartment($site['department'])->create();
        $this->grant($reader, $this->companyScope, ['numbering.view', 'documents.metadata.view']);
        $this->signIn($reader);
        $this->postJson('/api/v1/numbering-series/xppl/activate', $this->scopeData($this->companyScope) + ['register_kind' => 'incoming', 'confirm' => true])->assertForbidden();
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['register_kind' => 'incoming', 'confirm' => true]);
        $unit = $site['scope'];
        $this->postJson('/api/v1/incoming-correspondence/'.$draft->id.'/register?'.http_build_query(['company_id' => $unit->companyId, 'site_id' => $unit->siteId, 'department_id' => $unit->departmentId]))->assertForbidden();
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_company_policy_activation_audit_cannot_be_rewritten(string $operation): void
    {
        $this->setupCompany();
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['confirm' => true]);
        $this->expectException(QueryException::class);
        if ($operation === 'delete') {
            DB::table('xppl_numbering_policies')->delete();
        } else {
            DB::table('xppl_numbering_policies')->update(['activated_at' => now()->addDay()]);
        }
    }

    public function test_activated_policy_blocks_migration_rollback_and_other_companies_cannot_activate(): void
    {
        $this->setupCompany();
        $otherCompany = Company::factory()->create();
        $otherScope = AuthorizationScope::context('company', $otherCompany->id)->persist();
        OrganizationMembership::factory()->for($this->actor)->create(['company_id' => $otherCompany->id]);
        $this->grant($this->actor, $otherScope, ['numbering.manage']);
        $this->postJson('/api/v1/numbering-series/xppl/activate', $this->scopeData($otherScope) + ['confirm' => true])->assertUnprocessable();
        $this->assertDatabaseCount('xppl_numbering_policies', 0);
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['confirm' => true]);
        $migration = require database_path('migrations/2026_11_03_054924_create_xppl_numbering_policies_table.php');
        try {
            $migration->down();
            $this->fail('An active policy must not be removed by rollback.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Cannot remove an activated', $exception->getMessage());
        }
        $this->assertDatabaseHas('xppl_numbering_policies', ['company_id' => $this->company->id, 'activated_by' => $this->actor->id]);
    }

    public function test_company_policy_continues_existing_history_and_cannot_be_activated_by_readers(): void
    {
        $this->setupCompany();
        $site = $this->site(withSeries: false);
        $historical = $this->draft($site, 'XPPL-ADM-SOP-0042-R00');
        $reader = User::factory()->create();
        $this->grant($reader, $this->companyScope, ['numbering.view']);
        $this->signIn($reader);
        $this->postJson('/api/v1/numbering-series/xppl/activate', $this->scopeData($this->companyScope) + ['confirm' => true])->assertForbidden();
        $this->signIn($this->actor);
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['confirm' => true]);
        $this->assertSame('XPPL-ADM-SOP-0043', $this->issue($site, $this->draft($site), null)->document_code);
        $this->assertSame('XPPL-ADM-SOP-0042-R00', $historical->fresh()->document_code);
        $this->assertDatabaseHas('xppl_numbering_counters', ['period' => 0, 'baseline' => 42, 'value' => 43]);
    }

    public function test_policy_registration_needs_no_configuration_permission_and_preserves_approved_allocations(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $this->enable($site);
        $this->issue($site, $this->draft($site), null);
        $before = DB::table('xppl_numbering_counters')->first();
        app(XpplNumbering::class)->activatePolicy($this->actor, $this->companyScope, ['confirm' => true]);
        $issuer = User::factory()->create();
        OrganizationMembership::factory()->for($issuer)->inDepartment($site['department'])->create();
        OrganizationMembership::factory()->for($issuer)->atSite(Site::findOrFail($site['scope']->siteId))->create();
        $this->grant($issuer, $site['scope']->authorizationScope(), ['controlled.drafts.manage', 'controlled.register', 'documents.metadata.view', 'numbering.issue']);
        $this->actor = $issuer;
        $record = $this->issue($site, $this->draft($site), null);
        $this->assertSame('XPPL-ADM-SOP-0002', $record->document_code);
        $this->assertSame($before->baseline, DB::table('xppl_numbering_counters')->value('baseline'));
        $this->assertDatabaseCount('xppl_numbering_namespaces', 1);
        $this->assertDatabaseCount('xppl_numbering_mappings', 1);
        $this->assertDatabaseCount('xppl_numbering_allocations', 2);
        $newSite = $this->site('ENG', 'DRW', withSeries: false);
        $this->grant($issuer, $newSite['scope']->authorizationScope(), ['controlled.drafts.manage', 'controlled.register', 'documents.metadata.view', 'numbering.issue']);
        $this->assertSame('XPPL-ENG-DRW-0001', $this->issue($newSite, $this->draft($newSite), null)->document_code);
    }

    public function test_official_setup_without_series_is_read_only_until_explicit_baseline_confirmation(): void
    {
        $this->setupCompany();
        $site = $this->site(withSeries: false);
        $historical = $this->draft($site, 'XPPL-ADM-SOP-0042-R00');
        $body = $this->scopeData($this->companyScope) + ['department_id' => $site['department_id'], 'type_id' => $site['type_id'], 'format' => 'xppl_controlled'];
        $report = $this->postJson('/api/v1/numbering-series/xppl/review', $body)->assertOk()->assertJsonPath('preview', 'XPPL-ADM-SOP-0043')->assertJsonPath('historical_maximum', 42)->json();
        $this->assertDatabaseCount('numbering_series', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => 'stale', 'next_sequence' => $report['next_sequence'], 'confirm' => true])->assertUnprocessable();
        $this->assertDatabaseCount('numbering_series', 0);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $report['token'], 'next_sequence' => $report['next_sequence']])->assertUnprocessable();
        $this->assertDatabaseCount('numbering_series', 0);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $report['token'], 'next_sequence' => $report['next_sequence'], 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('numbering_series', 1);
        $this->assertDatabaseCount('numbering_configurations', 1);
        $this->assertDatabaseHas('xppl_numbering_counters', ['period' => 0, 'baseline' => 42, 'value' => 42]);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
        $draft = $this->draft($site);
        $this->assertNull($draft->document_code);
        $record = $this->issue($site, $draft, null);
        $this->assertSame('XPPL-ADM-SOP-0043', $record->document_code);
        $this->assertSame($record->id, $this->issue($site, $draft, null)->id);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertDatabaseCount('xppl_numbering_allocations', 1);
        $this->assertSame('XPPL-ADM-SOP-0042-R00', $historical->fresh()->document_code);
        $this->postJson('/api/v1/numbering-series/xppl/review', $body)->assertOk()->assertJsonPath('preview', 'XPPL-ADM-SOP-0044');
    }

    public function test_direct_official_setup_reuses_existing_series_without_modifying_configuration(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $before = DB::table('numbering_configurations')->get()->toArray();
        $body = $this->scopeData($this->companyScope) + ['department_id' => $site['department_id'], 'type_id' => $site['type_id'], 'format' => 'xppl_controlled'];
        $report = $this->postJson('/api/v1/numbering-series/xppl/review', $body)->assertOk()->json();
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $report['token'], 'next_sequence' => $report['next_sequence'], 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('numbering_series', 1);
        $this->assertEquals($before, DB::table('numbering_configurations')->get()->toArray());
    }

    public function test_direct_official_setup_requires_company_authority_and_company_matching_masters(): void
    {
        $this->setupCompany();
        $site = $this->site(withSeries: false);
        $body = $this->scopeData($this->companyScope) + ['department_id' => $site['department_id'], 'type_id' => $site['type_id'], 'format' => 'xppl_controlled'];
        $report = $this->postJson('/api/v1/numbering-series/xppl/review', $body)->assertOk()->json();
        $reader = User::factory()->create();
        $this->grant($reader, $this->companyScope, ['numbering.view']);
        $this->signIn($reader);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $report['token'], 'next_sequence' => $report['next_sequence'], 'confirm' => true])->assertForbidden();
        $this->signIn($this->actor);
        $foreignDepartment = Department::factory()->create();
        $this->postJson('/api/v1/numbering-series/xppl/review', array_replace($body, ['department_id' => $foreignDepartment->id]))->assertUnprocessable();
        $this->assertDatabaseCount('numbering_series', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
    }

    public function test_unnumbered_xppl_draft_registers_from_server_mapping_and_retries_do_not_allocate(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $scope = $site['scope'];
        $manager = app(ManageControlledDocuments::class);
        $this->assertFalse($manager->automaticNumbering($scope, $site['type_id'])['ready']);
        $body = ['type_id' => $site['type_id'], 'classification' => 'normal', 'title_en' => 'Automatic draft', 'custodian_id' => $this->actor->id,
            'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial issue', 'change_description' => 'First issue of the document'];
        $record = $manager->save($this->actor, $scope, $body);
        $this->assertNull($record->document_code);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        try {
            $this->issue($site, $record, null);
            $this->fail('An unapproved baseline must block registration.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('controlled', $failure->errors());
        }
        $this->enable($site);
        $this->assertSame('XPPL-ADM-SOP-0001-R00', $manager->automaticNumbering($scope, $site['type_id'])['preview']);
        try {
            $manager->register($this->actor, $scope, (string) $record->id, ['lock_version' => 1, 'namespace_id' => 999]);
            $this->fail('Client namespace overrides must be rejected.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('namespace_id', $failure->errors());
        }
        DB::beginTransaction();
        $this->issue($site, $record, null);
        DB::rollBack();
        $this->assertNull($record->fresh()->document_code);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertSame(0, (int) DB::table('xppl_numbering_counters')->value('value'));
        $url = '/api/v1/controlled-documents/lookups?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'department_id' => $scope->departmentId]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.automatic_numbering.preview', 'XPPL-ADM-SOP-0001-R00')->assertJsonMissingPath('data.0.automatic_numbering.namespace_id');
        $registered = $this->issue($site, $record, null);
        $this->assertSame('XPPL-ADM-SOP-0001', $registered->document_code);
        $this->assertSame($registered->document_code, $registered->internal_number);
        $this->assertSame($registered->id, $this->issue($site, $record, null)->id);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertDatabaseCount('controlled_registration_links', 1);
        $this->assertSame('XPPL-ADM-SOP-0002-R00', $manager->automaticNumbering($scope, $site['type_id'])['preview']);
        $this->assertSame('XPPL-ADM-SOP-0001-R00', (new ControlledDocumentResource($registered))->resolve()['revisions'][0]['full_identifier']);
    }

    /** @return array<string, mixed> */
    private function site(string $department = 'ADM', string $typeCode = 'SOP', bool $outgoing = false, bool $withSeries = true): array
    {
        $site = Site::factory()->create(['company_id' => $this->company->id]);
        $scope = AuthorizationScope::context('site', $this->company->id, $site->id)->persist();
        $dept = Department::factory()->create(['company_id' => $this->company->id, 'site_id' => $site->id, 'code' => $department, 'name_en' => $department, 'name_lo' => $department]);
        OrganizationMembership::factory()->for($this->actor)->atSite($site)->create();
        OrganizationMembership::factory()->for($this->actor)->inDepartment($dept)->create();
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => $outgoing ? 'outgoing' : 'controlled']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id, 'code' => 'DOC']);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id, 'code' => $typeCode, 'name_en' => $typeCode, 'name_lo' => $typeCode]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $series = $withSeries ? app(ManageNumbering::class)->save($this->actor, $scope, ['register_id' => $register->id, 'purpose' => $outgoing ? 'official_outgoing' : 'internal_register',
            'reset_policy' => 'annual', 'prefix' => 'LEGACY/S'.$site->id, 'padding' => 6, 'active' => true]) : null;
        if ($outgoing && $withSeries) {
            app(ManageNumbering::class)->save($this->actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'INTERNAL/S'.$site->id, 'padding' => 6, 'active' => true]);
        }

        return ['scope' => new DocumentScope($this->company->id, $site->id, $dept->id), 'type' => $type, 'department' => $dept,
            'series_id' => $series?->id, 'department_id' => $dept->id, 'type_id' => $type->id, 'format' => $outgoing ? 'xppl_letter' : 'xppl_controlled'];
    }

    /** @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function mapping(array $site): array
    {
        return array_intersect_key($site, array_flip(['series_id', 'department_id', 'type_id', 'format']));
    }

    /** @param array<string, mixed> $site */
    private function enable(array $site): int
    {
        $manager = app(XpplNumbering::class);
        $data = $this->mapping($site);
        $report = $manager->review($this->actor, $this->companyScope, $data);
        $result = $manager->review($this->actor, $this->companyScope, $data + ['token' => $report['token'], 'next_sequence' => $report['next_sequence'], 'confirm' => true], true);

        return $result['namespace_id'];
    }

    /** @param array<string, mixed> $site */
    private function draft(array $site, ?string $code = null): ControlledDocument
    {
        $automatic = $code === null && (app(ManageControlledDocuments::class)->automaticNumbering($site['scope'], $site['type_id'])['ready'] ?? false);

        return app(ManageControlledDocuments::class)->save($this->actor, $site['scope'], ($automatic ? [] : ['document_code' => $code ?? 'DRAFT/'.mb_strtoupper(bin2hex(random_bytes(5)))]) + [
            'type_id' => $site['type_id'], 'classification' => 'normal', 'title_en' => 'Test document', 'owner_id' => $this->actor->id, 'custodian_id' => $this->actor->id,
            'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial', 'change_description' => 'Initial']);
    }

    /** @param array<string, mixed> $site */
    private function issue(array $site, ControlledDocument $draft, ?int $namespace): ControlledDocument
    {
        return app(ManageControlledDocuments::class)->register($this->actor, $site['scope'], (string) $draft->id,
            ['lock_version' => $draft->lock_version] + ($namespace === null || $draft->document_code === null ? [] : ['namespace_id' => $namespace]));
    }

    public function test_two_sites_share_continuous_numbering_and_revision_does_not_allocate(): void
    {
        $this->setupCompany();
        $first = $this->site();
        $second = $this->site();
        $namespace = $this->enable($first);
        $this->assertSame($namespace, $this->enable($second));
        $record = $this->issue($first, $this->draft($first), $namespace);
        $this->assertSame('XPPL-ADM-SOP-0001', $record->internal_number);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('XPPL-ADM-SOP-0002', $this->issue($second, $this->draft($second), $namespace)->internal_number);
        $manager = app(ManageControlledDocuments::class);
        $record = $manager->release($this->actor, $first['scope'], (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions()->sole()->id, 'reason' => 'Release']);
        $released = $record->currentRevision->getAttributes();
        $record = $manager->revise($this->actor, $first['scope'], (string) $record->id, ['lock_version' => $record->lock_version, 'revision_reason' => 'Review', 'change_description' => 'Update']);
        $resource = (new ControlledDocumentResource($record))->resolve();
        $this->assertSame('XPPL-ADM-SOP-0001-R00', $resource['revisions'][0]['full_identifier']);
        $this->assertSame('XPPL-ADM-SOP-0001-R01', $resource['revisions'][1]['full_identifier']);
        $this->assertSame($released, $record->currentRevision->fresh()->getAttributes());
        $this->assertDatabaseCount('xppl_numbering_allocations', 2);
        $this->assertSame(2, (int) DB::table('xppl_numbering_counters')->value('value'));
    }

    public function test_departments_types_and_companies_have_independent_namespaces(): void
    {
        $this->setupCompany();
        foreach ([['ADM', 'SOP'], ['ADM', 'SDF'], ['ENG', 'DRW']] as [$dept, $type]) {
            $site = $this->site($dept, $type);
            $namespace = $this->enable($site);
            $this->assertSame('XPPL-'.$dept.'-'.$type.'-0001', $this->issue($site, $this->draft($site), $namespace)->internal_number);
        }
        $this->company = Company::factory()->create(['code' => 'OTHER']);
        $this->companyScope = AuthorizationScope::context('company', $this->company->id)->persist();
        OrganizationMembership::factory()->for($this->actor)->create(['company_id' => $this->company->id]);
        $this->grant($this->actor, $this->companyScope, ['numbering.view', 'numbering.manage', 'numbering.issue', 'documents.metadata.view', 'controlled.drafts.manage', 'controlled.register']);
        $site = $this->site();
        $this->assertSame('OTHER-ADM-SOP-0001', $this->issue($site, $this->draft($site), $this->enable($site))->internal_number);
    }

    public function test_reconciliation_reserves_historical_manual_codes_without_rewriting_them(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $historical = $this->draft($site, 'XPPL-ADM-SOP-0042-R00');
        $legacy = $this->issue($site, $historical, null);
        $namespace = $this->enable($site);
        $report = app(XpplNumbering::class)->review($this->actor, $this->companyScope, $this->mapping($site));
        $this->assertSame(43, $report['next_sequence']);
        $this->assertSame('XPPL-ADM-SOP-0043', $this->issue($site, $this->draft($site), $namespace)->internal_number);
        $this->assertSame('XPPL-ADM-SOP-0042-R00', $legacy->fresh()->document_code);
        $this->assertStringStartsWith('LEGACY/', $legacy->fresh()->internal_number);
        $this->assertSame(1, (int) DB::table('xppl_numbering_counters')->count());
    }

    #[TestWith(['mapping'])]
    #[TestWith(['collision'])]
    #[TestWith(['malformed'])]
    public function test_ambiguous_reconciliation_cannot_be_confirmed(string $problem): void
    {
        $this->setupCompany();
        $site = $this->site();
        $other = $this->site();
        if ($problem === 'mapping') {
            $other['department']->update(['name_en' => 'Different business identity']);
        } elseif ($problem === 'collision') {
            $this->draft($site, 'XPPL-ADM-SOP-0005-R00');
            $this->draft($other, 'XPPL-ADM-SOP-0005-R01');
        } else {
            $this->draft($site, 'XPPL-ADM-SOP-WRONG');
        }
        $data = $this->mapping($site);
        $report = app(XpplNumbering::class)->review($this->actor, $this->companyScope, $data);
        $this->assertTrue($report['ambiguous'] || $report['collision_count'] > 0);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $this->scopeData($this->companyScope) + $data + ['token' => $report['token'], 'next_sequence' => $report['next_sequence'], 'confirm' => true])->assertUnprocessable();
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
    }

    public function test_stale_review_and_unconfirmed_baseline_cannot_allocate(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $data = $this->mapping($site);
        $report = app(XpplNumbering::class)->review($this->actor, $this->companyScope, $data);
        $this->draft($site, 'XPPL-ADM-SOP-0009');
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $this->scopeData($this->companyScope) + $data + ['token' => $report['token'], 'next_sequence' => 1, 'confirm' => true])->assertUnprocessable();
        $draft = $this->draft($site);
        try {
            $this->issue($site, $draft, 999);
            $this->fail('Unconfirmed allocation succeeded.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('issued_document_numbers', 0);
            $this->assertDatabaseCount('numbering_counters', 0);
        }
    }

    public function test_registration_rollback_restores_shared_and_legacy_allocation(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $namespace = $this->enable($site);
        $draft = $this->draft($site);
        DB::beginTransaction();
        $this->issue($site, $draft, $namespace);
        DB::rollBack();
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('xppl_numbering_allocations', 0);
        $this->assertSame(0, (int) DB::table('xppl_numbering_counters')->value('value'));
        $this->assertSame('XPPL-ADM-SOP-0001', $this->issue($site, $draft->fresh(), $namespace)->internal_number);
    }

    public function test_missing_department_wrong_mapping_and_inactive_master_are_rejected(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $other = $this->site('ENG');
        $namespace = $this->enable($site);
        $draft = $this->draft($other);
        try {
            $this->issue($other, $draft, $namespace);
            $this->fail('Wrong mapping succeeded.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('issued_document_numbers', 0);
        }
        $siteWide = $site;
        $siteWide['scope'] = new DocumentScope($this->company->id, $site['scope']->siteId);
        $draft = $this->draft($siteWide);
        try {
            $this->issue($siteWide, $draft, $namespace);
            $this->fail('Missing department succeeded.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('issued_document_numbers', 0);
        }
        $site['type']->update(['active' => false]);
        $this->postJson('/api/v1/numbering-series/xppl/review', $this->scopeData($this->companyScope) + $this->mapping($site))->assertUnprocessable();
    }

    public function test_company_authorization_and_scope_isolation_apply_to_cutover(): void
    {
        $this->setupCompany();
        $site = $this->site();
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->inDepartment($site['department'])->create();
        $this->grant($user, $site['scope']->authorizationScope(), ['numbering.view', 'numbering.manage']);
        $this->signIn($user);
        $this->postJson('/api/v1/numbering-series/xppl/review', $this->scopeData($this->companyScope) + $this->mapping($site))->assertForbidden();
        $this->signIn($this->actor);
        $this->postJson('/api/v1/numbering-series/xppl/review', $this->scopeData($site['scope']->authorizationScope()) + $this->mapping($site))->assertUnprocessable();
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
    }

    public function test_http_cutover_is_explicit_and_cannot_spoof_codes_or_change_legacy_configuration(): void
    {
        $this->setupCompany();
        $site = $this->site('ADM', 'SDF');
        $body = $this->scopeData($this->companyScope) + $this->mapping($site) + ['prefix' => 'SPOOF-ENG-SOP'];
        $this->getJson($this->scopedUrl('numbering-series/xppl/options', $this->companyScope))->assertOk()->assertJsonPath('departments.0.code', 'ADM');
        $review = $this->postJson('/api/v1/numbering-series/xppl/review', $body)->assertOk()->assertJsonPath('preview', 'XPPL-ADM-SDF-0001')->json();
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $review['token'], 'next_sequence' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/numbering-series/xppl/confirm', $body + ['token' => $review['token'], 'next_sequence' => 1, 'confirm' => true])->assertOk()->assertJsonPath('confirmed', true);
        $this->assertDatabaseCount('numbering_configurations', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('xppl_numbering_allocations', 0);
        $this->assertDatabaseHas('xppl_numbering_counters', ['baseline' => 0, 'confirmed_by' => $this->actor->id]);
        $this->getJson($this->scopedUrl('numbering-series/xppl/options', $this->companyScope))->assertOk()->assertJsonPath('history.0.baseline', 0)->assertJsonPath('mappings.0.type_id', $site['type_id']);
        $this->expectException(ValidationException::class);
        $this->draft($site, 'XPPL-ADM-SDF-9999');
    }

    #[TestWith(['xppl_numbering_allocations', 'format', 'legacy'])]
    #[TestWith(['xppl_numbering_namespaces', 'prefix', 'OTHER'])]
    #[TestWith(['xppl_numbering_counters', 'value', 0])]
    public function test_database_guards_shared_history_and_counter_integrity(string $table, string $field, mixed $value): void
    {
        $this->setupCompany();
        $site = $this->site();
        $namespace = $this->enable($site);
        $this->issue($site, $this->draft($site), $namespace);

        $this->expectException(QueryException::class);
        DB::table($table)->update([$field => $value]);
    }

    public function test_official_letters_reset_annually_and_internal_numbers_keep_legacy_format(): void
    {
        $this->setupCompany();
        $site = $this->site('ENG', 'LTR', true);
        $namespace = $this->enable($site);
        $manager = app(ManageOutgoingCorrespondence::class);
        foreach ([2026, 2027] as $year) {
            $this->travelTo(now()->setDate($year, 10, 26));
            if ($year === 2027) {
                $this->enable($site);
            }
            $draft = $manager->save($this->actor, $site['scope'], ['type_id' => $site['type_id'], 'classification' => 'normal', 'subject' => 'Letter', 'signatory_name' => 'Signer', 'document_date' => $year.'-10-26', 'priority' => 'normal', 'recipients' => [['kind' => 'primary', 'organization' => 'Recipient']]]);
            $record = $manager->register($this->actor, $site['scope'], (string) $draft->id, $namespace);
            $this->assertSame('XPPL-ENG-LTR-'.substr((string) $year, -2).'-0001', $record->official_number);
            $this->assertStringStartsWith('INTERNAL/', $record->internal_number);
            $this->assertStringContainsString('/'.$year.'/000001', $record->internal_number);
        }
        $this->assertDatabaseCount('xppl_numbering_allocations', 2);
    }
}
