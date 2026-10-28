<?php

namespace Tests\Feature;

use App\Authorization\DocumentAccess;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Authorization\ManagePhysicalCustody;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function searchUrl(object $record, array $filters = []): string
    {
        return '/api/v1/document-search?'.http_build_query($filters + ['company_id' => $record->profile->company_id, 'site_id' => $record->profile->site_id]);
    }

    public function test_searches_all_registers_and_keeps_number_identities_separate(): void
    {
        [$incoming, $actor] = $this->fixture();
        $scope = new DocumentScope($incoming->profile->company_id, $incoming->profile->site_id);
        $type = DocumentType::findOrFail($incoming->type_id);
        foreach (['outgoing', 'controlled'] as $key) {
            $register = RegisterConfiguration::factory()->create(['scope_id' => $incoming->scope_id, 'register_key' => $key]);
            $type->registers()->attach($register->id, ['scope_id' => $incoming->scope_id]);
        }
        $outgoing = app(ManageOutgoingCorrespondence::class)->save($actor, $scope, ['type_id' => $type->id, 'subject' => 'ຄວາມປອດໄພ', 'document_date' => '2026-10-26', 'signatory_name' => 'Example', 'signatory_title' => 'Officer', 'classification' => 'normal', 'priority' => 'normal', 'recipients' => [['kind' => 'primary', 'organization' => 'Example']]]);
        $controlled = app(ManageControlledDocuments::class)->save($actor, $scope, ['type_id' => $type->id, 'document_code' => 'DEMO/SOP/001', 'title_en' => 'Safety procedure', 'title_lo' => 'ຄວາມປອດໄພ', 'owner_id' => $actor->id, 'custodian_id' => $actor->id, 'classification' => 'normal', 'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial issue', 'change_description' => 'Initial procedure']);

        $this->getJson($this->searchUrl($incoming))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(3, 'data');
        $this->getJson($this->searchUrl($incoming, ['search' => 'ຄວາມປອດໄພ']))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->searchUrl($incoming, ['search' => 'DEMO/SOP/001', 'exact' => 1]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $controlled->id)->assertJsonPath('data.0.register', 'controlled')->assertJsonPath('data.0.internal_number', null);
        $this->getJson($this->searchUrl($incoming, ['register' => 'outgoing']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $outgoing->id);
    }

    public function test_unauthorized_workspace_and_invalid_queries_are_rejected(): void
    {
        [$record] = $this->fixture();
        $this->getJson($this->searchUrl($record, ['sort' => 'id desc; drop table users']))->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson($this->searchUrl($record, ['per_page' => 100]))->assertUnprocessable();
        $this->signIn(User::factory()->create());
        $this->getJson($this->searchUrl($record))->assertForbidden();
    }

    public function test_restricted_rows_and_counts_are_indistinguishable_from_absent_matches(): void
    {
        [$record, $actor] = $this->fixture('incoming', 'highly_confidential');
        $reader = User::factory()->create();
        $site = Site::findOrFail($record->profile->site_id);
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        $this->grant($reader, AuthorizationScope::findOrFail($record->scope_id), ['documents.metadata.view']);
        $this->signIn($reader);

        $hidden = $this->getJson($this->searchUrl($record, ['search' => $record->subject]))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data')->json();
        $absent = $this->getJson($this->searchUrl($record, ['search' => 'does not exist']))->assertOk()->json();
        $this->assertSame($hidden, $absent);
        $this->signIn($actor);
        $this->getJson($this->searchUrl($record))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_combines_category_type_dates_status_and_classification_filters(): void
    {
        [$record] = $this->fixture();
        $filters = ['category_id' => $record->type->category_id, 'type_id' => $record->type_id, 'date_from' => '2026-10-26', 'date_to' => '2026-10-26', 'status' => 'draft', 'classification' => 'normal'];

        $this->getJson($this->searchUrl($record, $filters))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->searchUrl($record, array_replace($filters, ['date_from' => '2026-10-27', 'date_to' => '2026-10-28'])))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->searchUrl($record, ['date_to' => '2026-10-26']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->searchUrl($record, ['date_from' => '2026-10-27', 'date_to' => '2026-10-26']))->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_historical_revisions_and_drafts_never_replace_authoritative_revision(): void
    {
        [$record, $actor] = $this->fixture('controlled');
        $scope = new DocumentScope($record->profile->company_id, $record->profile->site_id);
        $manager = app(ManageControlledDocuments::class);
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'DEMO/CD', 'padding' => 6, 'active' => true]);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version]);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions->first()->id, 'reason' => 'Initial release']);
        $record = $manager->revise($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_reason' => 'Historical search beacon', 'change_description' => 'Changed synthetic procedure']);
        $draft = $record->revisions->last();
        $this->getJson($this->searchUrl($record, ['search' => 'Historical search beacon', 'revision' => '00']))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->searchUrl($record, ['search' => 'Historical search beacon']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.authoritative_revision', 0)->assertJsonPath('data.0.draft_revision', 1);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $draft->id, 'reason' => 'Second release']);

        $this->getJson($this->searchUrl($record, ['revision' => '00', 'revision_state' => 'superseded']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.authoritative_revision', 1)->assertJsonPath('data.0.draft_revision', null);
        $this->getJson($this->searchUrl($record, ['revision' => '00', 'revision_state' => 'current']))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->searchUrl($record, ['owner' => $actor->name, 'revision' => 1]))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->searchUrl($record, ['revision' => 'R00', 'revision_state' => 'superseded']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->searchUrl($record, ['revision' => 'R01', 'revision_state' => 'current']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->searchUrl($record, ['revision' => 'R02']))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->searchUrl($record, ['revision' => 'Rinvalid']))->assertUnprocessable();
    }

    public function test_physical_location_search_and_retrieval_keep_content_permissions_separate(): void
    {
        [$record, $actor] = $this->fixture();
        $scope = new DocumentScope($record->profile->company_id, $record->profile->site_id);
        $custody = app(ManagePhysicalCustody::class);
        $location = $custody->location($actor, $scope, 'incoming', (string) $record->id, ['room' => 'Synthetic archive', 'cabinet' => 'Cabinet A', 'shelf' => 'Shelf B', 'box' => 'Box C']);
        $copy = $custody->create($actor, $scope, 'incoming', (string) $record->id, null, ['identifier' => 'PHYS/DEMO/001', 'kind' => 'original', 'location_id' => $location->id, 'reason' => 'Synthetic fixture']);
        $version = $this->postJson($this->url($record), ['file' => $this->pdf(), 'reason' => 'Synthetic upload'])->assertCreated()->json('data.versions.0.id');
        $reader = User::factory()->create();
        $site = Site::findOrFail($scope->siteId);
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        $this->grant($reader, $scope->authorizationScope(), ['documents.metadata.view']);
        $this->signIn($reader);

        $this->getJson($this->searchUrl($record, ['physical' => 'Box C', 'copy_kind' => 'original', 'custody_status' => 'stored']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.physical_copies.0.identifier', $copy->identifier)->assertJsonPath('data.0.physical_copies.0.location', 'Synthetic archive → Cabinet A → Shelf B → Box C');
        $this->getJson($this->searchUrl($record, ['physical' => $copy->identifier, 'copy_kind' => 'copy']))->assertOk()->assertJsonPath('meta.total', 0);
        $custody->change($actor, $scope, 'incoming', (string) $record->id, null, $copy->id, ['action' => 'checkout', 'lock_version' => 1, 'reason' => 'Synthetic checkout', 'custodian_id' => $actor->id, 'checkout_date' => today()->format('Y-m-d'), 'expected_return_date' => today()->addDay()->format('Y-m-d')]);
        $this->getJson($this->searchUrl($record, ['custodian' => $actor->name, 'custody_status' => 'checked_out']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.physical_copies.0.custodian', $actor->name);
        $this->getJson($this->url($record))->assertOk()->assertJsonPath('capabilities.preview', false)->assertJsonPath('capabilities.download', false);
        $this->getJson($this->url($record, 'versions/'.$version.'/preview'))->assertNotFound();
        $this->getJson($this->url($record, 'versions/'.$version.'/download'))->assertNotFound();
        $record->profile->update(['restricted' => true]);
        $this->getJson($this->searchUrl($record, ['physical' => $copy->identifier]))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    #[TestWith(['normal', false, false, false, false, true])]
    #[TestWith(['normal', true, false, false, false, false])]
    #[TestWith(['normal', true, false, true, false, true])]
    #[TestWith(['confidential', false, false, true, false, false])]
    #[TestWith(['confidential', false, true, false, false, true])]
    #[TestWith(['highly_confidential', true, true, false, false, false])]
    #[TestWith(['highly_confidential', true, true, true, false, true])]
    #[TestWith(['highly_confidential', true, true, true, true, false])]
    public function test_query_authorization_agrees_with_canonical_policy(string $classification, bool $restricted, bool $clearance, bool $grant, bool $expired, bool $expected): void
    {
        [$record] = $this->fixture('incoming', $classification);
        $this->freezeTime();
        if ($restricted && ! $record->profile->restricted) {
            $record->profile->update(['restricted' => true]);
        }
        $reader = User::factory()->create();
        $site = Site::findOrFail($record->profile->site_id);
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        $this->grant($reader, AuthorizationScope::findOrFail($record->scope_id), ['documents.metadata.view']);
        if ($clearance) {
            ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id, 'expires_at' => $expired ? now()->subSecond() : now()->addDay()]);
        }
        if ($grant) {
            DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        }
        $canonical = app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($record->profile), DocumentOperation::Metadata);
        $this->assertSame($expected, $canonical);
        $this->signIn($reader);

        $this->getJson($this->searchUrl($record, ['search' => $record->subject, 'per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('meta.total', $expected ? 1 : 0)->assertJsonCount(0, 'data');
        $this->getJson($this->searchUrl($record, ['classification' => $classification]))->assertOk()->assertJsonPath('meta.total', $expected ? 1 : 0);
    }

    public function test_company_site_and_exact_department_membership_isolate_search(): void
    {
        [$record, $actor] = $this->fixture('incoming', 'confidential');
        $site = Site::findOrFail($record->profile->site_id);
        $department = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
        $unit = new DocumentScope($site->company_id, $site->id, $department->id);
        $departmentRecord = app(ManageIncomingCorrespondence::class)->save($actor, $unit, ['type_id' => $record->type_id, 'classification' => 'normal', 'subject' => 'Department only beacon', 'sender_organization' => 'Synthetic', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email', 'priority' => 'normal']);
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($site)->create();
        $this->grant($reader, AuthorizationScope::findOrFail($record->scope_id), ['documents.metadata.view']);
        $this->signIn($reader);

        $this->getJson($this->searchUrl($record, ['search' => $departmentRecord->subject]))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->searchUrl($record, ['department_id' => $department->id]))->assertForbidden();
        OrganizationMembership::factory()->for($reader)->inDepartment($department)->create();
        $this->getJson($this->searchUrl($record, ['department_id' => $department->id]))->assertOk()->assertJsonPath('meta.total', 1);
        $otherSite = Site::factory()->create(['company_id' => $site->company_id]);
        $this->getJson($this->searchUrl($record, ['site_id' => $otherSite->id]))->assertForbidden();
        $otherCompanySite = Site::factory()->create();
        $this->getJson($this->searchUrl($record, ['company_id' => $otherCompanySite->company_id, 'site_id' => $otherCompanySite->id]))->assertForbidden();
    }

    public function test_pagination_is_stable_and_wildcards_are_literal(): void
    {
        [$record, $actor] = $this->fixture();
        $manager = app(ManageIncomingCorrespondence::class);
        $scope = $record->profile->documentScope();
        foreach (['100% literal', '1000 ordinary', 'underscore_name'] as $subject) {
            $manager->save($actor, $scope, ['type_id' => $record->type_id, 'classification' => 'normal', 'subject' => $subject, 'sender_organization' => 'Synthetic', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email', 'priority' => 'normal']);
        }
        $first = $this->getJson($this->searchUrl($record, ['per_page' => 2, 'sort' => 'date']))->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $second = $this->getJson($this->searchUrl($record, ['per_page' => 2, 'page' => 2, 'sort' => 'date']))->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $this->assertCount(4, array_unique(array_column([...$first, ...$second], 'key')));
        $this->assertSame($first, $this->getJson($this->searchUrl($record, ['per_page' => 2, 'sort' => 'date']))->json('data'));
        $this->assertSame('2026-10-26', $first[0]['document_date']);
        $this->getJson($this->searchUrl($record, ['search' => '%']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title_en', '100% literal');
        $this->getJson($this->searchUrl($record, ['search' => '_']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title_en', 'underscore_name');
        $this->getJson($this->searchUrl($record, ['search' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_exact_internal_official_and_external_numbers_remain_distinct(): void
    {
        [$record, $actor] = $this->fixture('outgoing');
        $scope = $record->profile->documentScope();
        $this->grant($actor, $scope->authorizationScope(), ['outgoing.register']);
        foreach (['internal_register' => 'DEMO/REG', 'official_outgoing' => 'DEMO/OFF'] as $purpose => $prefix) {
            app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => $prefix, 'padding' => 6, 'active' => true]);
        }
        $record = app(ManageOutgoingCorrespondence::class)->register($actor, $scope, (string) $record->id);

        foreach ([$record->internal_number, $record->official_number] as $number) {
            $this->getJson($this->searchUrl($record, ['search' => $number, 'exact' => 1]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.internal_number', $record->internal_number)->assertJsonPath('data.0.official_number', $record->official_number)->assertJsonPath('data.0.external_reference', null);
            $this->getJson($this->searchUrl($record, ['search' => $number.'X', 'exact' => 1]))->assertOk()->assertJsonPath('meta.total', 0);
        }
        [$incoming, $actor] = $this->fixture();
        $incoming->update(['external_reference' => 'EXTERNAL/ເລກທີ/001']);
        $this->getJson($this->searchUrl($incoming, ['search' => $incoming->external_reference, 'exact' => 1]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.external_reference', $incoming->external_reference)->assertJsonPath('data.0.internal_number', null);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    #[TestWith(['controlled'])]
    public function test_restricted_register_revision_and_location_matches_never_leak(string $register): void
    {
        [$record, $actor] = $this->fixture($register, 'highly_confidential');
        $scope = $record->profile->documentScope();
        $location = app(ManagePhysicalCustody::class)->location($actor, $scope, $register, (string) $record->id, ['room' => 'Secret room beacon']);
        app(ManagePhysicalCustody::class)->create($actor, $scope, $register, (string) $record->id, $register === 'controlled' ? $record->revisions->first()->id : null, ['identifier' => 'SECRET/PHYS/001', 'kind' => 'original', 'location_id' => $location->id, 'reason' => 'Synthetic fixture']);
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite(Site::findOrFail($scope->siteId))->create();
        $this->grant($reader, $scope->authorizationScope(), ['documents.metadata.view', 'documents.attachments.download']);
        ConfidentialityClearance::factory()->forProfile($record->profile)->create(['user_id' => $reader->id]);
        DocumentAccessGrant::factory()->create(['profile_id' => $record->profile_id, 'user_id' => $reader->id, 'operation' => 'download']);
        $this->signIn($reader);

        foreach ([[], ['search' => $record->document_code ?? $record->subject, 'exact' => 1], ['physical' => 'Secret room beacon'], ['physical' => 'SECRET/PHYS/001'], ['register' => $register, 'classification' => 'highly_confidential'], ...($register === 'controlled' ? [['revision' => '00'], ['search' => 'Initial']] : [])] as $filters) {
            $this->getJson($this->searchUrl($record, $filters))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/v1/'.($register === 'controlled' ? 'controlled-documents' : $register.'-correspondence').'/'.$record->id.'?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId]))->assertNotFound();
        $this->signIn($actor);
        $this->getJson($this->searchUrl($record))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('document_security_audits', ['actor_id' => $actor->id, 'profile_id' => $record->profile_id, 'event' => 'access.allowed', 'operation' => 'metadata', 'reason' => 'system_administrator']);
    }
}
