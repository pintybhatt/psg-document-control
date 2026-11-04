<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageIncomingCorrespondence;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityAudit;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ConfidentialityAdministrationTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private function scopeBody(Site $site, ?Department $department = null): array
    {
        return ['company_id' => $site->company_id, 'site_id' => $site->id, 'department_id' => $department?->id];
    }

    private function endpoint(string $path, array $scope): string
    {
        return '/api/v1/confidentiality/'.$path.'?'.http_build_query($scope);
    }

    /** @param list<string> $permissions */
    private function member(Site $site, array $permissions = [], ?Department $department = null): User
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::factory()->for($user);
        ($department ? $membership->inDepartment($department) : $membership->atSite($site))->create();
        if ($permissions !== []) {
            $this->grant($user, AuthorizationScope::context('site', $site->company_id, $site->id), $permissions);
        }

        return $user;
    }

    public function test_clearance_management_lists_eligible_users_and_preserves_audited_history(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site);
        $inactive = $this->member($site);
        $inactive->deactivate();
        $foreign = $this->member(Site::factory()->create());
        $this->signIn($manager);
        $scope = $this->scopeBody($site);
        $this->getJson($this->endpoint('users', $scope))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)
            ->assertJsonMissing(['email' => $foreign->email])->assertJsonMissing(['email' => $inactive->email]);
        $this->getJson($this->endpoint('users', $scope + ['q' => 'no-such-synthetic-user']))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'confidential', 'expires_at' => now()->addDay()->toISOString()])->assertCreated();
        $clearance = ConfidentialityClearance::query()->sole();
        $this->assertDatabaseHas('document_security_audits', ['event' => 'clearance.granted', 'actor_id' => $manager->id, 'target_user_id' => $target->id, 'clearance_id' => $clearance->id]);
        $this->getJson($this->endpoint('clearances', $scope))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.revocable', true);
        $this->deleteJson($this->endpoint('clearances/'.$clearance->id, $scope))->assertOk();
        $this->getJson($this->endpoint('clearances', $scope))->assertOk()->assertJsonPath('data.0.status', 'revoked')->assertJsonPath('data.0.revocable', false);
        $this->deleteJson($this->endpoint('clearances/'.$clearance->id, $scope))->assertUnprocessable();
        $this->assertDatabaseHas('document_security_audits', ['event' => 'clearance.revoked', 'clearance_id' => $clearance->id]);
        $this->assertDatabaseCount('confidentiality_clearances', 1);
        $this->assertNotNull($clearance->refresh()->expires_at);
    }

    public function test_security_only_department_manager_can_enter_administration_without_site_wide_authority(): void
    {
        $this->definitions();
        $department = Department::factory()->create();
        $site = $department->site;
        $manager = $this->member($site, ['clearances.confidential.manage'], $department);
        $target = $this->member($site, [], $department);
        $siteUser = $this->member($site);
        $this->signIn($manager);
        $scope = $this->scopeBody($site, $department);
        $this->get('/administration')->assertOk();
        $this->getJson('/api/v1/authorization/contexts?administration=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_manage_document_security', true)->assertJsonPath('data.0.permissions', []);
        $this->getJson($this->endpoint('contexts', $this->scopeBody($site)))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.department_id', $department->id);
        $this->getJson($this->endpoint('users', $scope))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)->assertJsonMissing(['email' => $siteUser->email]);
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'confidential'])->assertCreated();
        $this->getJson($this->endpoint('clearances', $this->scopeBody($site)))->assertForbidden();
        $other = Department::factory()->for($site)->create(['company_id' => $site->company_id]);
        $this->getJson($this->endpoint('clearances', $this->scopeBody($site, $other)))->assertForbidden();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_foreign_company_or_site_is_not_discoverable_or_mutable(bool $sameCompany): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $foreign = $sameCompany ? Site::factory()->for($site->company)->create() : Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage', 'document-access.manage']);
        $target = $this->member($foreign);
        $this->signIn($manager);
        foreach (['contexts', 'users', 'clearances', 'documents', 'documents/incoming/1/grants'] as $path) {
            $this->getJson($this->endpoint($path, $this->scopeBody($foreign)))->assertForbidden();
        }
        $this->postJson('/api/v1/confidentiality/clearances', $this->scopeBody($foreign) + ['user_id' => $target->id, 'classification' => 'confidential'])->assertForbidden();
        $this->postJson('/api/v1/confidentiality/clearances', $this->scopeBody($site) + ['user_id' => $target->id, 'classification' => 'confidential'])->assertNotFound();
        $this->assertDatabaseCount('confidentiality_clearances', 0);
    }

    public function test_unauthorized_self_and_higher_classification_grants_are_rejected(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site);
        $scope = $this->scopeBody($site);
        $this->signIn($target);
        $this->get('/administration')->assertForbidden();
        $this->getJson($this->endpoint('users', $scope))->assertForbidden();
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $manager->id, 'classification' => 'confidential'])->assertForbidden();
        $this->signIn($manager);
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $manager->id, 'classification' => 'confidential'])->assertForbidden();
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'highly_confidential'])->assertForbidden();
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'normal'])->assertUnprocessable();
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'confidential', 'expires_at' => now()->subMinute()->toISOString()])->assertUnprocessable();
        $hidden = ConfidentialityClearance::factory()->for($target)->create($scope + ['classification' => Confidentiality::HighlyConfidential]);
        $this->getJson($this->endpoint('clearances', $scope))->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($this->endpoint('clearances/'.$hidden->id, $scope))->assertNotFound();
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    #[TestWith(['controlled'])]
    public function test_document_grants_are_atomic_operation_specific_expiring_and_audited(string $resource): void
    {
        [$record, $manager] = $this->fixture($resource, 'highly_confidential');
        $site = Site::findOrFail($record->profile->site_id);
        $scope = $this->scopeBody($site);
        $target = $this->member($site, ['documents.metadata.view', 'documents.content.preview', 'documents.attachments.download']);
        $base = 'documents/'.$resource.'/'.$record->id.'/grants';
        $this->getJson($this->endpoint('documents', $scope))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id)->assertJsonMissingPath('data.0.profile_id');
        $this->postJson('/api/v1/confidentiality/clearances', $scope + ['user_id' => $target->id, 'classification' => 'highly_confidential'])->assertCreated();
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $target->id, 'operations' => ['metadata', 'preview'], 'expires_at' => now()->addHour()->toISOString()])->assertCreated();
        $this->getJson($this->endpoint($base, $scope))->assertOk()->assertJsonCount(2, 'data');
        $descriptor = DocumentSecurityDescriptor::fromProfile($record->profile);
        $access = app(DocumentAccess::class);
        $this->assertTrue($access->allows($target, $descriptor, 'metadata'));
        $this->assertTrue($access->allows($target, $descriptor, 'preview'));
        $this->assertFalse($access->allows($target, $descriptor, 'download'));
        $this->assertSame(2, DocumentSecurityAudit::where('event', 'grant.granted')->count());
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $target->id, 'operations' => ['download', 'metadata']])->assertUnprocessable();
        $this->assertDatabaseCount('document_access_grants', 2);
        $this->assertSame(2, DocumentSecurityAudit::where('event', 'grant.granted')->count());
        $preview = DocumentAccessGrant::where('operation', 'preview')->sole();
        $this->deleteJson($this->endpoint($base.'/'.$preview->id, $scope))->assertOk();
        $this->assertFalse($access->allows($target, $descriptor, 'preview'));
        $this->assertDatabaseHas('document_security_audits', ['event' => 'grant.revoked', 'grant_id' => $preview->id, 'actor_id' => $manager->id]);
        $this->travel(2)->hours();
        $this->assertFalse($access->allows($target, $descriptor, 'metadata'));
        $this->getJson($this->endpoint($base, $scope))->assertOk()->assertJsonPath('data.1.status', 'expired');
        $this->assertSame(Confidentiality::HighlyConfidential, $record->profile->refresh()->classification);
        $this->assertTrue($record->profile->restricted);
    }

    public function test_document_security_managers_cannot_discover_or_manage_invisible_metadata(): void
    {
        [$record] = $this->fixture('incoming', 'highly_confidential');
        $site = Site::findOrFail($record->profile->site_id);
        $scope = $this->scopeBody($site);
        $manager = $this->member($site, ['document-access.manage', 'documents.metadata.view']);
        $target = $this->member($site);
        $this->signIn($manager);
        $base = 'documents/incoming/'.$record->id.'/grants';
        $this->getJson($this->endpoint('documents', $scope))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint($base, $scope))->assertNotFound();
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $target->id, 'operations' => ['metadata']])->assertNotFound();
        $this->deleteJson($this->endpoint($base.'/999', $scope))->assertNotFound();
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_document_self_grants_invalid_operations_and_foreign_grant_ids_are_rejected(): void
    {
        [$record, $manager] = $this->fixture('incoming', 'highly_confidential');
        $scope = $this->scopeBody(Site::findOrFail($record->profile->site_id));
        $target = $this->member(Site::findOrFail($record->profile->site_id));
        $base = 'documents/incoming/'.$record->id.'/grants';
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $manager->id, 'operations' => ['metadata']])->assertForbidden();
        foreach ([['metadata', 'metadata'], ['delete'], []] as $operations) {
            $this->postJson($this->endpoint($base, $scope), ['user_id' => $target->id, 'operations' => $operations])->assertUnprocessable();
        }
        $this->deleteJson($this->endpoint($base.'/999999', $scope))->assertNotFound();
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_department_document_grants_require_exact_target_membership_and_preserve_other_grants(): void
    {
        [$siteRecord, $manager] = $this->fixture('incoming', 'highly_confidential');
        $site = Site::findOrFail($siteRecord->profile->site_id);
        $department = Department::factory()->for($site)->create(['company_id' => $site->company_id]);
        $scope = $this->scopeBody($site, $department);
        $record = app(ManageIncomingCorrespondence::class)->save($manager,
            new DocumentScope($site->company_id, $site->id, $department->id), [
                'type_id' => $siteRecord->type_id, 'subject' => 'Department confidential letter', 'priority' => 'normal',
                'classification' => 'highly_confidential', 'sender_organization' => 'Example',
                'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email',
            ]);
        $departmentManager = $this->member($site, ['document-access.manage', 'documents.metadata.view'], $department);
        ConfidentialityClearance::factory()->for($departmentManager)->create($scope + ['classification' => 'highly_confidential']);
        DocumentAccessGrant::factory()->for($departmentManager, 'user')->create(['profile_id' => $record->profile_id, 'operation' => 'metadata']);
        $target = $this->member($site, [], $department);
        $other = $this->member($site);
        $base = 'documents/incoming/'.$record->id.'/grants';
        $this->signIn($departmentManager);
        $this->getJson($this->endpoint('documents', $scope))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $other->id, 'operations' => ['download']])->assertNotFound();
        $this->postJson($this->endpoint($base, $scope), ['user_id' => $target->id, 'operations' => ['download']])->assertCreated();
        $grant = DocumentAccessGrant::where('user_id', $target->id)->sole();
        $this->getJson($this->endpoint('documents/incoming/'.$siteRecord->id.'/grants', $scope))->assertNotFound();
        $this->getJson($this->endpoint($base, $this->scopeBody($site)))->assertForbidden();
        $target->deactivate();
        $this->getJson($this->endpoint($base, $scope))->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson($this->endpoint($base.'/'.$grant->id, $scope))->assertOk();
        $this->assertNotNull($grant->refresh()->revoked_at);
        $this->assertNull(DocumentAccessGrant::where('user_id', $departmentManager->id)->sole()->revoked_at);
        $this->assertDatabaseCount('user_role_assignments', 3);
    }

    public function test_document_management_without_metadata_permission_does_not_enable_empty_administration_navigation(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['document-access.manage']);
        $this->signIn($manager);
        $this->get('/administration')->assertForbidden();
        $this->getJson('/api/v1/authorization/contexts?administration=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint('contexts', $this->scopeBody($site)))->assertForbidden();
        $this->getJson($this->endpoint('documents', $this->scopeBody($site)))->assertForbidden();
    }

    public function test_audit_failure_rolls_back_an_http_clearance_grant(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site);
        $this->signIn($manager);
        $event = 'eloquent.creating: '.DocumentSecurityAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Synthetic audit failure');
        });
        try {
            $this->postJson('/api/v1/confidentiality/clearances', $this->scopeBody($site) + ['user_id' => $target->id, 'classification' => 'confidential'])->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_security_audits', 0);
    }
}
