<?php

namespace Tests\Feature;

use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManagePhysicalCustody;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentAccessGrant;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $scope = $record->profile->documentScope();
        $manager = app(ManageControlledDocuments::class);
        $record->revisions->first()->update(['review_date' => '2026-10-29']);
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'REPORT/CD', 'padding' => 6, 'active' => true]);
        $record = $manager->register($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version]);
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions->first()->id, 'reason' => 'Synthetic release']);
        $record = $manager->revise($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_reason' => 'Synthetic annual review', 'change_description' => 'Prepare future review']);
        $record->revisions->last()->update(['review_date' => '2027-10-30']);

        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.controlled', 1)->assertJsonPath('totals.authoritative', 1)->assertJsonPath('totals.draft_documents', 1)->assertJsonPath('totals.review_overdue', 1)->assertJsonPath('totals.review_soon', 0)->assertJsonCount(1, 'reviews');
        $record = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $record->revisions->last()->id, 'reason' => 'Release reviewed procedure']);
        $this->getJson($this->reportUrl($record))->assertOk()->assertJsonPath('totals.controlled', 1)->assertJsonPath('totals.authoritative', 1)->assertJsonPath('totals.draft_documents', 0)->assertJsonPath('totals.review_overdue', 0);
    }
}
