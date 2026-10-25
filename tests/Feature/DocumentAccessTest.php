<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function reader(DocumentSecurityProfile $profile, array $permissions): User
    {
        $user = User::factory()->create();
        if ($profile->department_id === null) {
            OrganizationMembership::factory()->for($user)->atSite(Site::query()->findOrFail($profile->site_id))->create();
        } else {
            OrganizationMembership::factory()->for($user)->inDepartment(Department::query()->findOrFail($profile->department_id))->create();
        }
        $this->grant($user, $profile->documentScope()->authorizationScope(), $permissions);

        return $user;
    }

    private function permits(User $user, DocumentSecurityProfile $profile, DocumentOperation|string $operation = DocumentOperation::Metadata): bool
    {
        return app(DocumentAccess::class)->allows($user, DocumentSecurityDescriptor::fromProfile($profile), $operation);
    }

    #[TestWith(['normal', true])]
    #[TestWith(['confidential', false])]
    #[TestWith(['highly_confidential', false])]
    public function test_classification_requires_clearance_beyond_role_and_membership(string $classification, bool $allowed): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::from($classification))->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);

        $this->assertSame($allowed, $this->permits($reader, $profile));
    }

    #[TestWith(['metadata'])]
    #[TestWith(['preview'])]
    #[TestWith(['download'])]
    public function test_document_operations_are_independent_and_require_a_resource(string $held): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create();
        $operation = DocumentOperation::from($held);
        $reader = $this->reader($profile, [$operation->permission()]);
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);

        foreach (DocumentOperation::cases() as $requested) {
            $this->assertSame($requested === $operation, Gate::forUser($reader)->allows($requested->permission(), $descriptor));
        }
        $this->assertFalse(Gate::forUser($reader)->allows($operation->permission(), $profile->documentScope()->authorizationScope()));
        $this->assertFalse(app(AccessControl::class)->allows($reader, $operation->permission(), $profile->documentScope()->authorizationScope()));
        $this->assertFalse(app(AccessControl::class)->allowsOperational($reader, $operation->permission(), $profile->documentScope()->authorizationScope()));
    }

    public function test_confidential_and_highly_confidential_clearances_are_exact_and_not_interchangeable(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $confidential = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::Confidential)->create();
        $highly = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::HighlyConfidential)->create();
        $reader = $this->reader($confidential, ['documents.metadata.view']);
        ConfidentialityClearance::factory()->for($reader)->forProfile($confidential)->create();
        DocumentAccessGrant::factory()->for($reader)->create(['profile_id' => $highly->id]);

        $this->assertTrue($this->permits($reader, $confidential));
        $this->assertFalse($this->permits($reader, $highly));
        ConfidentialityClearance::factory()->for($reader)->forProfile($highly)->create();
        $this->assertTrue($this->permits($reader, $highly));
    }

    public function test_highly_confidential_clearance_alone_never_replaces_document_and_operation_specific_allowlists(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();
        $reader = $this->reader($profile, ['documents.metadata.view', 'documents.content.preview', 'documents.attachments.download']);
        ConfidentialityClearance::factory()->for($reader)->forProfile($profile)->create();

        $this->assertFalse($this->permits($reader, $profile));
        DocumentAccessGrant::factory()->for($reader)->create(['profile_id' => $profile->id, 'operation' => DocumentOperation::Preview]);
        $this->assertTrue($this->permits($reader, $profile, DocumentOperation::Preview));
        $this->assertFalse($this->permits($reader, $profile, DocumentOperation::Download));
        $this->assertFalse($this->permits($reader, $profile, DocumentOperation::Metadata));
        $other = DocumentSecurityProfile::factory()->atSite(Site::query()->findOrFail($profile->site_id))->classified(Confidentiality::HighlyConfidential)->create();
        $this->assertFalse($this->permits($reader, $other, DocumentOperation::Preview));
    }

    public function test_restrictions_apply_to_normal_metadata_as_well_as_content(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create(['restricted' => true]);
        $reader = $this->reader($profile, ['documents.metadata.view', 'documents.content.preview']);

        $this->assertFalse($this->permits($reader, $profile));
        DocumentAccessGrant::factory()->for($reader)->create(['profile_id' => $profile->id]);
        $this->assertTrue($this->permits($reader, $profile));
        $this->assertFalse($this->permits($reader, $profile, DocumentOperation::Preview));
    }

    #[TestWith(['role'])]
    #[TestWith(['membership'])]
    public function test_neither_role_nor_membership_alone_grants_document_access(string $missing): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create();
        $reader = $this->reader($profile, $missing === 'role' ? [] : ['documents.metadata.view']);
        if ($missing === 'membership') {
            $reader->memberships()->firstOrFail()->update(['active' => false]);
        }

        $this->assertFalse($this->permits($reader, $profile));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_group_role_and_clearance_do_not_bypass_company_or_site_membership(bool $sameCompany): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create();
        $reader = $this->reader($profile, []);
        $this->grant($reader, AuthorizationScope::context('global'), ['documents.metadata.view']);
        $otherSite = $sameCompany ? Site::factory()->for(Site::query()->findOrFail($profile->site_id)->company)->create() : Site::factory()->create();
        $other = DocumentSecurityProfile::factory()->atSite($otherSite)->create();

        $this->assertTrue($this->permits($reader, $profile));
        $this->assertFalse($this->permits($reader, $other));
        $this->assertSame('membership_denied', DocumentSecurityAudit::query()->latest('id')->firstOrFail()->reason);
    }

    public function test_department_membership_and_clearance_do_not_cross_departments_or_widen_to_site(): void
    {
        $this->definitions();
        $department = Department::factory()->create();
        $other = Department::factory()->for($department->site)->create();
        $profile = DocumentSecurityProfile::factory()->inDepartment($department)->classified(Confidentiality::Confidential)->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);
        ConfidentialityClearance::factory()->for($reader)->forProfile($profile)->create();
        $sibling = DocumentSecurityProfile::factory()->inDepartment($other)->classified(Confidentiality::Confidential)->create();
        $siteProfile = DocumentSecurityProfile::factory()->atSite($department->site)->create();

        $this->assertTrue($this->permits($reader, $profile));
        $this->assertFalse($this->permits($reader, $sibling));
        $this->assertFalse($this->permits($reader, $siteProfile));
        OrganizationMembership::factory()->for($reader)->inDepartment($other)->create();
        $this->assertFalse($this->permits($reader, $sibling));
        $this->assertSame('clearance_denied', DocumentSecurityAudit::query()->latest('id')->firstOrFail()->reason);
    }

    public function test_security_administration_never_implies_document_reading_or_clearance(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();
        $administrator = $this->reader($profile, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS]);
        ConfidentialityClearance::factory()->for($administrator)->forProfile($profile)->create();
        DocumentAccessGrant::factory()->for($administrator)->create(['profile_id' => $profile->id]);

        $this->assertFalse($this->permits($administrator, $profile));
        $this->assertFalse($this->permits($administrator, $profile, DocumentOperation::Preview));
        $this->assertFalse($this->permits($administrator, $profile, DocumentOperation::Download));
        $this->assertTrue(app(AccessControl::class)->allows($administrator, 'roles.manage', $profile->documentScope()->authorizationScope()));
    }

    #[TestWith(['inactive'])]
    #[TestWith(['expired'])]
    #[TestWith(['expires-now'])]
    #[TestWith(['revoked'])]
    public function test_inactive_expired_or_revoked_clearances_are_denied(string $state): void
    {
        $this->definitions();
        $this->freezeTime();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::Confidential)->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $clearance = ConfidentialityClearance::factory()->for($reader)->forProfile($profile)->create();
        $this->assertTrue($this->permits($reader, $profile));

        match ($state) {
            'inactive' => $clearance->update(['active' => false]),
            'expired' => $clearance->update(['expires_at' => now()->subMinute()]),
            'expires-now' => $clearance->update(['expires_at' => now()]),
            'revoked' => $clearance->forceFill(['active' => false, 'revoked_by' => $clearance->created_by, 'revoked_at' => now()])->save(),
        };

        $this->assertFalse($this->permits($reader, $profile));
    }

    #[TestWith(['inactive'])]
    #[TestWith(['expired'])]
    #[TestWith(['expires-now'])]
    #[TestWith(['revoked'])]
    public function test_inactive_expired_or_revoked_document_grants_are_denied(string $state): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create(['restricted' => true]);
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $grant = DocumentAccessGrant::factory()->for($reader)->create(['profile_id' => $profile->id]);
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        $this->assertTrue(app(DocumentAccess::class)->allows($reader, $descriptor, 'metadata'));

        match ($state) {
            'inactive' => $grant->update(['active' => false]), 'expired' => $grant->update(['expires_at' => now()->subMinute()]),
            'expires-now' => $grant->update(['expires_at' => now()]),
            'revoked' => $grant->forceFill(['active' => false, 'revoked_by' => $grant->created_by, 'revoked_at' => now()])->save(),
        };

        $this->assertFalse(app(DocumentAccess::class)->allows($reader, $descriptor, 'metadata'));
    }

    public function test_server_descriptor_ignores_forged_loaded_model_attributes_and_rejects_browser_context_arrays(): void
    {
        $this->definitions();
        $local = DocumentSecurityProfile::factory()->create();
        $foreign = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();
        $reader = $this->reader($local, ['documents.metadata.view']);
        $foreign->company_id = $local->company_id;
        $foreign->site_id = $local->site_id;
        $foreign->classification = Confidentiality::Normal;
        $foreign->restricted = false;
        $descriptor = DocumentSecurityDescriptor::fromProfile($foreign);

        $this->assertFalse(app(DocumentAccess::class)->allows($reader, $descriptor, 'metadata'));
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, ['profile_id' => $local->id, 'company_id' => $local->company_id, 'classification' => 'normal'], 'metadata'));
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, $local, 'metadata'));
        $this->assertFalse(app(DocumentAccess::class)->allows($reader, DocumentSecurityDescriptor::fromProfile($local), 'unknown-operation'));
        $this->assertFalse(app(DocumentAccess::class)->allows(null, DocumentSecurityDescriptor::fromProfile($local), 'metadata'));
    }

    #[TestWith(['user'])]
    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['department'])]
    #[TestWith(['profile'])]
    public function test_inactive_account_organization_or_resource_denies_access(string $record): void
    {
        $this->definitions();
        $department = Department::factory()->create();
        $profile = DocumentSecurityProfile::factory()->inDepartment($department)->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $this->assertTrue($this->permits($reader, $profile));

        match ($record) {
            'user' => $reader->deactivate(), 'company' => $department->company->update(['active' => false]),
            'site' => $department->site->update(['active' => false]), 'department' => $department->update(['active' => false]),
            'profile' => $profile->update(['active' => false]),
        };

        $this->assertFalse($this->permits($reader, $profile));
    }

    public function test_access_audits_are_minimal_and_denial_messages_do_not_disclose_resource_metadata(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create(['resource_key' => 'private-resource-reference']);
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $this->assertTrue($this->permits($reader, $profile));
        try {
            app(DocumentAccess::class)->authorize($reader, DocumentSecurityDescriptor::fromProfile($profile), DocumentOperation::Download);
            $this->fail('Download was authorized.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Document access denied.', $exception->getMessage());
        }

        $audits = DocumentSecurityAudit::query()->orderBy('id')->get();
        $this->assertSame(['access.allowed', 'access.denied'], $audits->pluck('event')->all());
        $this->assertSame(['metadata', 'download'], $audits->pluck('operation')->all());
        $this->assertSame($reader->id, $audits->last()->actor_id);
        $this->assertSame($profile->id, $audits->last()->profile_id);
        $this->assertNull($audits->last()->after);
        $this->assertStringNotContainsString('private-resource-reference', $audits->toJson());
    }

    public function test_audit_failure_does_not_return_an_allowed_result(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $event = 'eloquent.creating: '.DocumentSecurityAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated audit failure');
        });
        $this->expectException(\RuntimeException::class);

        try {
            $this->permits($reader, $profile);
        } finally {
            Event::forget($event);
        }
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    #[TestWith(['controlled'])]
    public function test_each_supported_resource_family_uses_the_same_policy_contract(string $type): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create(['resource_type' => $type]);
        $reader = $this->reader($profile, ['documents.metadata.view']);

        $this->assertTrue($this->permits($reader, $profile));
    }

    public function test_document_rights_can_be_combined_from_multiple_roles_without_granting_download(): void
    {
        $this->definitions();
        $profile = DocumentSecurityProfile::factory()->create();
        $reader = $this->reader($profile, ['documents.metadata.view']);
        $this->grant($reader, $profile->documentScope()->authorizationScope(), ['documents.content.preview']);

        $this->assertTrue($this->permits($reader, $profile, DocumentOperation::Metadata));
        $this->assertTrue($this->permits($reader, $profile, DocumentOperation::Preview));
        $this->assertFalse($this->permits($reader, $profile, DocumentOperation::Download));
    }

    public function test_highly_confidential_clearance_does_not_silently_include_confidential_entitlement(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $highly = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::HighlyConfidential)->create();
        $confidential = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::Confidential)->create();
        $reader = $this->reader($highly, ['documents.metadata.view']);
        ConfidentialityClearance::factory()->for($reader)->forProfile($highly)->create();
        DocumentAccessGrant::factory()->for($reader)->create(['profile_id' => $highly->id]);

        $this->assertTrue($this->permits($reader, $highly));
        $this->assertFalse($this->permits($reader, $confidential));
    }
}
