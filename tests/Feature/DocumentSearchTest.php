<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\AuthorizationScope;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $this->grant($reader, $site->authorizationScope ?? AuthorizationScope::findOrFail($record->scope_id), ['documents.metadata.view']);
        $this->signIn($reader);

        $hidden = $this->getJson($this->searchUrl($record, ['search' => $record->subject]))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data')->json();
        $absent = $this->getJson($this->searchUrl($record, ['search' => 'does not exist']))->assertOk()->json();
        $this->assertSame($hidden, $absent);
        $this->signIn($actor);
        $this->getJson($this->searchUrl($record))->assertOk()->assertJsonPath('meta.total', 1);
    }
}
