<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentScope;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageDocumentSecurity;
use App\Authorization\ManageMemberships;
use App\Authorization\ManageRoles;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\RbacAudit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class SecurityAuditReviewTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, DocumentSecurityDescriptor} */
    private function reader(bool $permitted): array
    {
        $this->definitions();
        $site = Site::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, AuthorizationScope::context('site', $site->company_id, $site->id), $permitted ? ['documents.metadata.view'] : []);
        $profile = DocumentSecurityProfile::factory()->atSite($site)->create();

        return [$user, DocumentSecurityDescriptor::fromProfile($profile)];
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_allowed_and_denied_access_audits_are_removed_by_a_caller_rollback(bool $permitted): void
    {
        [$user, $descriptor] = $this->reader($permitted);
        $baseline = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $this->assertSame($permitted, app(DocumentAccess::class)->allows($user, $descriptor, 'metadata'));
            $this->assertSame($permitted ? 'access.allowed' : 'access.denied', DocumentSecurityAudit::query()->sole()->event);
        } finally {
            DB::rollBack();
        }

        $this->assertSame($baseline, DB::transactionLevel());
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    public function test_authorization_exception_rolls_back_its_denial_audit_with_the_callers_transaction(): void
    {
        [$user, $descriptor] = $this->reader(false);
        try {
            DB::transaction(fn () => app(DocumentAccess::class)->authorize($user, $descriptor, 'metadata'));
            $this->fail('Denied access was allowed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Document access denied.', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_security_audits', 0);
    }

    public function test_caught_denial_without_a_caller_rollback_keeps_the_current_connection_audit(): void
    {
        [$user, $descriptor] = $this->reader(false);
        try {
            app(DocumentAccess::class)->authorize($user, $descriptor, 'metadata');
            $this->fail('Denied access was allowed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Document access denied.', $exception->getMessage());
        }

        $this->assertSame('access.denied', DocumentSecurityAudit::query()->sole()->event);
    }

    public function test_successful_caller_transaction_retains_its_access_audit(): void
    {
        [$user, $descriptor] = $this->reader(true);

        $allowed = DB::transaction(fn (): bool => app(DocumentAccess::class)->allows($user, $descriptor, 'metadata'));

        $this->assertTrue($allowed);
        $this->assertSame('access.allowed', DocumentSecurityAudit::query()->sole()->event);
    }

    #[TestWith(['rbac'])]
    #[TestWith(['membership'])]
    #[TestWith(['clearance'])]
    public function test_outer_rollback_removes_security_mutation_and_its_transactional_audit(string $kind): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        if ($kind === 'clearance') {
            OrganizationMembership::factory()->for($target)->atSite($site)->create();
        }
        $this->grant($actor, $scope, [...PermissionCatalog::ADMINISTRATOR_KEYS, ...PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS, 'clearances.confidential.manage']);
        $table = match ($kind) {
            'rbac' => 'roles', 'membership' => 'organization_memberships', default => 'confidentiality_clearances'
        };
        $auditTable = match ($kind) {
            'rbac' => 'rbac_audits', 'membership' => 'membership_audits', default => 'document_security_audits'
        };
        $before = DB::table($table)->count();

        try {
            DB::transaction(function () use ($kind, $actor, $target, $site, $scope, $auditTable): void {
                match ($kind) {
                    'rbac' => app(ManageRoles::class)->saveRole($actor, $scope, ['code' => 'AUDIT_REVIEW_ROLE', 'name_en' => 'Fictional review role', 'name_lo' => 'ບົດບາດຕົວຢ່າງ', 'active' => true, 'permission_keys' => ['roles.view']]),
                    'membership' => app(ManageMemberships::class)->assign($actor, $scope, ['user_id' => $target->id, 'unit_type' => 'site', 'unit_company_id' => $site->company_id, 'unit_site_id' => $site->id]),
                    'clearance' => app(ManageDocumentSecurity::class)->grantClearance($actor, $target, new DocumentScope($site->company_id, $site->id), Confidentiality::Confidential),
                };
                $this->assertSame(1, DB::table($auditTable)->count());
                throw new \RuntimeException('Rollback review transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback review transaction', $exception->getMessage());
        }

        $this->assertSame($before, DB::table($table)->count());
        $this->assertDatabaseCount($auditTable, 0);
    }

    #[TestWith(['rbac', false])]
    #[TestWith(['membership', false])]
    #[TestWith(['document', true])]
    public function test_database_audit_protection_boundary_is_explicit(string $kind, bool $databaseProtected): void
    {
        $actor = User::factory()->create();
        $scope = AuthorizationScope::context('global')->persist();
        $audit = match ($kind) {
            'rbac' => RbacAudit::query()->create(['actor_id' => $actor->id, 'source' => 'web', 'action' => 'review.fixture', 'subject_type' => 'role', 'subject_id' => 1, 'scope_id' => $scope->id]),
            'membership' => MembershipAudit::query()->create(['actor_id' => $actor->id, 'target_user_id' => $actor->id, 'membership_id' => OrganizationMembership::factory()->for($actor)->create()->id, 'action' => 'assigned', 'after' => []]),
            'document' => DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'event' => 'access.denied', 'reason' => 'unsupported_resource']),
        };
        $column = $kind === 'document' ? 'reason' : 'action';

        try {
            $affected = DB::table($audit->getTable())->where('id', $audit->id)->update([$column => 'review.changed']);
            $this->assertFalse($databaseProtected, 'The audit trigger unexpectedly permitted a write.');
            $this->assertSame(1, $affected);
        } catch (QueryException $exception) {
            $this->assertTrue($databaseProtected);
            $this->assertSame('unsupported_resource', $audit->refresh()->reason);
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_document_authorization_fails_closed_when_allowed_or_denied_audit_writes_fail(bool $permitted): void
    {
        [$user, $descriptor] = $this->reader($permitted);
        $event = 'eloquent.creating: '.DocumentSecurityAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Review audit sink unavailable');
        });
        $this->expectException(\RuntimeException::class);

        try {
            app(DocumentAccess::class)->authorize($user, $descriptor, 'metadata');
        } finally {
            Event::forget($event);
        }
    }

    public function test_rbac_mutation_is_rolled_back_if_its_audit_write_fails(): void
    {
        $this->definitions();
        $scope = AuthorizationScope::context('global')->persist();
        $actor = User::factory()->create();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $event = 'eloquent.creating: '.RbacAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Review audit sink unavailable');
        });
        try {
            app(ManageRoles::class)->saveRole($actor, $scope, ['code' => 'FAILED_AUDIT_ROLE', 'name_en' => 'Review role', 'name_lo' => 'ບົດບາດຕົວຢ່າງ', 'active' => true, 'permission_keys' => []]);
            $this->fail('An unaudited role was saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Review audit sink unavailable', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseMissing('roles', ['code' => 'FAILED_AUDIT_ROLE']);
        $this->assertDatabaseCount('rbac_audits', 0);
    }
}
