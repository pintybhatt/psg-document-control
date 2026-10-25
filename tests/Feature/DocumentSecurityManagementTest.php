<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentResourceType;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageDocumentSecurity;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentSecurityManagementTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function member(Site $site, array $permissions): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, AuthorizationScope::context('site', $site->company_id, $site->id), $permissions);

        return $user;
    }

    private function forbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('An unauthorized security mutation was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_manager_can_register_a_security_profile_without_receiving_document_access(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['document-access.manage']);
        $scope = new DocumentScope($site->company_id, $site->id);

        $descriptor = app(ManageDocumentSecurity::class)->registerProfile($manager, DocumentResourceType::Controlled, 'example-persisted-resource', $scope, Confidentiality::HighlyConfidential);

        $this->assertTrue($descriptor->resolve()->restricted);
        $this->assertSame($manager->id, $descriptor->resolve()->created_by);
        $this->assertSame('profile.registered', DocumentSecurityAudit::query()->sole()->event);
        $this->assertFalse(app(DocumentAccess::class)->allows($manager, $descriptor, DocumentOperation::Metadata));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_clearance_changes_are_audited_and_revocation_is_immediate_in_an_existing_session(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $reader = $this->member($site, ['documents.metadata.view']);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::Confidential)->create();
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        $service = app(ManageDocumentSecurity::class);
        $clearance = $service->grantClearance($manager, $reader, $profile->documentScope(), Confidentiality::Confidential, CarbonImmutable::now()->addDay());
        $this->signIn($reader);
        $reader->load('clearances');
        $this->assertTrue(Gate::allows('documents.metadata.view', $descriptor));

        $service->revoke($manager, $clearance);

        $this->assertFalse(Gate::allows('documents.metadata.view', $descriptor));
        $this->getJson('/api/v1/user')->assertOk();
        $this->assertAuthenticatedAs($reader);
        $this->assertSame($manager->id, $clearance->refresh()->revoked_by);
        $this->assertNotNull($clearance->revoked_at);
        $audit = DocumentSecurityAudit::query()->where('event', 'clearance.revoked')->sole();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertSame($reader->id, $audit->target_user_id);
        $this->assertTrue((bool) $audit->before['active']);
        $this->assertFalse((bool) $audit->after['active']);
        $this->assertFalse(app(DocumentAccess::class)->allows($manager, $descriptor, 'metadata'));
    }

    public function test_allowlist_revocation_and_tightening_apply_to_an_existing_descriptor(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['document-access.manage']);
        $reader = $this->member($site, ['documents.metadata.view', 'documents.content.preview']);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->create();
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        $service = app(ManageDocumentSecurity::class);
        $access = app(DocumentAccess::class);
        $this->assertTrue($access->allows($reader, $descriptor, 'metadata'));

        $service->requireAllowlist($manager, $descriptor);
        $this->assertFalse($access->allows($reader, $descriptor, 'metadata'));
        $grant = $service->allowDocument($manager, $reader, $descriptor, DocumentOperation::Metadata);
        $this->assertTrue($access->allows($reader, $descriptor, 'metadata'));
        $this->assertFalse($access->allows($reader, $descriptor, 'preview'));
        $service->revoke($manager, $grant);
        $this->assertFalse($access->allows($reader, $descriptor, 'metadata'));
        $this->assertSame($manager->id, $grant->refresh()->revoked_by);
        $this->assertSame($profile->id, DocumentSecurityAudit::query()->where('event', 'grant.revoked')->sole()->profile_id);
    }

    public function test_self_clearance_and_document_allowlist_grants_are_forbidden(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['document-access.manage', 'clearances.confidential.manage']);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->create(['restricted' => true]);
        $service = app(ManageDocumentSecurity::class);

        $this->forbidden(fn () => $service->grantClearance($manager, $manager, $profile->documentScope(), Confidentiality::Confidential));
        $this->forbidden(fn () => $service->allowDocument($manager, $manager, DocumentSecurityDescriptor::fromProfile($profile), DocumentOperation::Metadata));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    public function test_confidential_managers_cannot_issue_highly_confidential_clearance(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site, []);

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->grantClearance($manager, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::HighlyConfidential));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_managers_cannot_grant_clearance_in_another_site_or_company(bool $sameCompany): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $foreign = $sameCompany ? Site::factory()->for($site->company)->create() : Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage', 'document-access.manage']);
        $target = $this->member($foreign, []);
        $profile = DocumentSecurityProfile::factory()->atSite($foreign)->create(['restricted' => true]);

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->grantClearance($manager, $target, $profile->documentScope(), Confidentiality::Confidential));
        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->allowDocument($manager, $target, DocumentSecurityDescriptor::fromProfile($profile), DocumentOperation::Metadata));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    public function test_group_technical_permissions_cannot_manage_clearances_without_matching_membership(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = User::factory()->create();
        $this->grant($manager, AuthorizationScope::context('global'), ['clearances.confidential.manage', 'document-access.manage']);
        $target = $this->member($site, []);

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->grantClearance($manager, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::Confidential));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
    }

    public function test_target_must_have_effective_membership_before_receiving_clearance(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = User::factory()->create();

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->grantClearance($manager, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::Confidential));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
    }

    public function test_clearance_suspension_and_expiry_changes_preserve_audit_history(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $reader = $this->member($site, ['documents.metadata.view']);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::Confidential)->create();
        $service = app(ManageDocumentSecurity::class);
        $clearance = $service->grantClearance($manager, $reader, $profile->documentScope(), Confidentiality::Confidential);

        $service->updateGrant($manager, $clearance, false);
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($profile), 'metadata'));
        $expiry = CarbonImmutable::now()->addHour()->startOfSecond();
        $service->updateGrant($manager, $clearance, true, $expiry);
        $this->assertTrue(app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($profile), 'metadata'));
        $this->assertSame($expiry->toISOString(), $clearance->refresh()->expires_at->toISOString());
        $this->assertSame(2, DocumentSecurityAudit::query()->where('event', 'clearance.updated')->count());
    }

    public function test_revoked_clearance_cannot_be_restored_and_new_grant_has_separate_history(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site, []);
        $scope = new DocumentScope($site->company_id, $site->id);
        $service = app(ManageDocumentSecurity::class);
        $old = $service->grantClearance($manager, $target, $scope, Confidentiality::Confidential);
        $service->revoke($manager, $old);
        try {
            $service->updateGrant($manager, $old, true);
            $this->fail('Revoked clearance was restored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['documentSecurityInvalid'], $exception->errors()['security']);
        }

        $new = $service->grantClearance($manager, $target, $scope, Confidentiality::Confidential);
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($old->refresh()->revoked_at);
        $this->assertDatabaseCount('confidentiality_clearances', 2);
    }

    public function test_revoked_manager_role_cannot_perform_a_stale_clearance_mutation(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site, []);
        $manager->roleAssignments()->sole()->update(['active' => false]);

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->grantClearance($manager, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::Confidential));
        $this->assertDatabaseCount('confidentiality_clearances', 0);
    }

    public function test_clearance_is_not_persisted_when_the_audit_write_fails(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $target = $this->member($site, []);
        $event = 'eloquent.creating: '.DocumentSecurityAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated audit failure');
        });

        try {
            app(ManageDocumentSecurity::class)->grantClearance($manager, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::Confidential);
            $this->fail('Unaudited clearance was persisted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    public function test_document_and_clearance_endpoints_are_not_exposed(): void
    {
        foreach (['/api/v1/documents', '/api/v1/clearances', '/incoming/1/preview', '/incoming/1/download'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/api/v1/clearances', [])->assertNotFound();
    }

    public function test_a_manager_cannot_extend_or_reactivate_their_own_existing_clearance(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $manager = $this->member($site, ['clearances.confidential.manage']);
        $clearance = ConfidentialityClearance::factory()->for($manager)->create([
            'company_id' => $site->company_id, 'site_id' => $site->id, 'active' => false,
        ]);

        $this->forbidden(fn () => app(ManageDocumentSecurity::class)->updateGrant($manager, $clearance, true));
        $this->assertFalse($clearance->refresh()->active);
        $this->assertDatabaseCount('document_security_audits', 0);
    }
}
