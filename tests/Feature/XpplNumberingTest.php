<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
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
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'controlled.drafts.manage', 'controlled.register', 'controlled.release', 'outgoing.drafts.manage', 'outgoing.register']);
        $this->signIn($this->actor);
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
    private function site(string $department = 'ADM', string $typeCode = 'SOP', bool $outgoing = false): array
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
        $series = app(ManageNumbering::class)->save($this->actor, $scope, ['register_id' => $register->id, 'purpose' => $outgoing ? 'official_outgoing' : 'internal_register',
            'reset_policy' => 'annual', 'prefix' => 'LEGACY/S'.$site->id, 'padding' => 6, 'active' => true]);
        if ($outgoing) {
            app(ManageNumbering::class)->save($this->actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'INTERNAL/S'.$site->id, 'padding' => 6, 'active' => true]);
        }

        return ['scope' => new DocumentScope($this->company->id, $site->id, $dept->id), 'type' => $type, 'department' => $dept,
            'series_id' => $series->id, 'department_id' => $dept->id, 'type_id' => $type->id, 'format' => $outgoing ? 'xppl_letter' : 'xppl_controlled'];
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
