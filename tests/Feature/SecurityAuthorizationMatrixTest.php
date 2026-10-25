<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageDocumentSecurity;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class SecurityAuthorizationMatrixTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array<string, array{string, list<bool>}> */
    public static function scenarios(): array
    {
        return [
            'guest' => ['guest', [false, false, false, false, false, false]],
            'no roles' => ['no_roles', [false, false, false, false, false, false]],
            'membership only' => ['membership_only', [false, false, false, false, false, false]],
            'roles without membership' => ['roles_only', [true, true, false, false, false, false]],
            'company membership only' => ['company_only', [true, true, false, false, false, false]],
            'company role and site membership' => ['company_with_site', [true, true, true, true, true, true]],
            'site role and membership' => ['site', [true, true, true, true, true, true]],
            'owning department membership' => ['department', [true, true, true, true, true, true]],
            'sibling site membership' => ['other_site', [true, true, false, false, false, false]],
            'other company membership' => ['other_company', [true, true, false, false, false, false]],
            'central security administration' => ['central', [true, true, false, false, false, false]],
            'inactive account' => ['inactive_user', [false, false, false, false, false, false]],
            'revoked session' => ['revoked_session', [false, false, false, false, false, false]],
            'inactive role' => ['inactive_role', [false, false, false, false, false, false]],
            'revoked role assignment' => ['revoked_assignment', [false, false, false, false, false, false]],
            'inactive membership' => ['inactive_membership', [true, true, false, false, false, false]],
            'revoked membership' => ['revoked_membership', [true, true, false, false, false, false]],
            'metadata without content rights' => ['metadata_only', [true, true, true, true, false, false]],
            'preview without metadata or download' => ['preview_only', [true, true, true, false, true, false]],
        ];
    }

    /** @param list<bool> $expected */
    #[DataProvider('scenarios')]
    public function test_authorization_integration_matrix(string $scenario, array $expected): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $department = $scenario === 'department' ? Department::factory()->for($site)->create() : null;
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $profile = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::HighlyConfidential)->create(['department_id' => $department?->id]);
        $target = User::factory()->create();
        $membershipFactory = OrganizationMembership::factory()->atSite($site);
        if ($department) {
            $membershipFactory = $membershipFactory->inDepartment($department);
        }
        $membershipFactory->for($target)->create();
        $actor = $scenario === 'guest' ? null : User::factory()->create();
        $membership = null;
        $role = null;
        $administrator = User::factory()->create();
        $this->grant($administrator, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
        if ($actor) {
            $keys = [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS,
                'clearances.highly-confidential.manage', 'document-access.manage',
                'documents.metadata.view', 'documents.content.preview', 'documents.attachments.download'];
            if ($scenario === 'central') {
                $keys = [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS];
            }
            if ($scenario === 'metadata_only') {
                $keys = array_values(array_diff($keys, ['documents.content.preview', 'documents.attachments.download']));
            }
            if ($scenario === 'preview_only') {
                $keys = array_values(array_diff($keys, ['documents.metadata.view', 'documents.attachments.download']));
            }
            $roleScope = match ($scenario) {
                'central' => AuthorizationScope::context('global'),
                'company_only', 'company_with_site' => AuthorizationScope::context('company', $site->company_id),
                default => $scope,
            };
            if (! in_array($scenario, ['no_roles', 'membership_only'], true)) {
                $role = $this->grant($actor, $roleScope, $keys);
            }
            if (! in_array($scenario, ['no_roles', 'roles_only', 'central'], true)) {
                if ($scenario === 'company_only') {
                    $membership = OrganizationMembership::factory()->for($actor)->create(['company_id' => $site->company_id]);
                } else {
                    $memberSite = match ($scenario) {
                        'other_site' => Site::factory()->for($site->company)->create(),
                        'other_company' => Site::factory()->create(),
                        default => $site,
                    };
                    $factory = OrganizationMembership::factory()->for($actor)->atSite($memberSite);
                    if ($department) {
                        $factory = $factory->inDepartment($department);
                    }
                    $membership = $factory->create();
                }
            }
            ConfidentialityClearance::factory()->for($actor)->forProfile($profile)->create();
            foreach (DocumentOperation::cases() as $operation) {
                DocumentAccessGrant::factory()->for($actor)->create(['profile_id' => $profile->id, 'operation' => $operation]);
            }
            $this->signIn($actor);
            match ($scenario) {
                'inactive_user' => $actor->deactivate(),
                'revoked_session' => $actor->update(['password' => 'Fictional-test-password-9988']),
                'inactive_role' => $role->update(['active' => false]),
                'revoked_assignment' => $role->assignments()->sole()->update(['active' => false]),
                'inactive_membership' => $membership->update(['active' => false]),
                'revoked_membership' => $membership->forceFill(['active' => false, 'revoked_at' => now(), 'revoked_by' => $administrator->id])->save(),
                default => null,
            };
        }

        $roleResponse = $this->getJson($this->scopedUrl('roles', $scope));
        $memberResponse = $this->getJson($this->scopedUrl('memberships', $scope));
        $authenticated = auth()->user();
        $deniedStatus = $authenticated ? 403 : 401;
        $roleResponse->assertStatus($expected[0] ? 200 : $deniedStatus);
        $memberResponse->assertStatus($expected[1] ? 200 : $deniedStatus);
        if ($authenticated) {
            try {
                $clearance = app(ManageDocumentSecurity::class)->grantClearance($authenticated, $target, $profile->documentScope(), Confidentiality::HighlyConfidential);
                $this->assertTrue($expected[2]);
                $this->assertSame($target->id, $clearance->user_id);
            } catch (HttpException $exception) {
                $this->assertFalse($expected[2]);
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame(0, $target->clearances()->count());
            }
        } else {
            $this->assertFalse($expected[2]);
        }
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        foreach (DocumentOperation::cases() as $index => $operation) {
            $this->assertSame($expected[$index + 3], app(DocumentAccess::class)->allows($authenticated, $descriptor, $operation));
        }
    }
}
