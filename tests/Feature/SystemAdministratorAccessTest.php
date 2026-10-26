<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentNumbering;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\ManageDocumentSecurity;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageRoles;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithRbac;
use Tests\Fixtures\FormalRegistrationRecord;
use Tests\TestCase;

class SystemAdministratorAccessTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function administrator(): User
    {
        $this->definitions();
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);

        return $user;
    }

    public function test_global_assignment_grants_all_available_system_capabilities_without_memberships(): void
    {
        $user = $this->administrator();
        $first = Site::factory()->create();
        $second = Site::factory()->create();
        $this->signIn($user);
        $access = app(AccessControl::class);

        foreach ([AuthorizationScope::context('global'), AuthorizationScope::context('company', $first->company_id), AuthorizationScope::context('site', $first->company_id, $first->id), AuthorizationScope::context('site', $second->company_id, $second->id)] as $scope) {
            foreach (PermissionCatalog::definitions() as $key => $definition) {
                if (! PermissionCatalog::requiresDocument($key)) {
                    $this->assertSame($definition['available'], Gate::forUser($user)->allows($key, $scope), $key);
                }
            }
        }
        foreach ([$first, $second] as $site) {
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
            $this->getJson($this->scopedUrl('document-master-data/categories', $scope))->assertOk();
            $this->getJson($this->scopedUrl('numbering-series', $scope))->assertOk();
            $this->postJson('/api/v1/document-master-data/registers', $this->scopeData($scope) + ['register_key' => 'incoming', 'active' => true])->assertCreated();
        }
        $contexts = $this->getJson('/api/v1/authorization/contexts')->assertOk()->json('data');
        $this->assertCount(5, $contexts);
        $this->getJson('/api/v1/organization-contexts')->assertOk()->assertJsonCount(4, 'data');
        $this->assertTrue($access->isSystemAdministrator($user));
        $this->assertFalse($access->allows($user, 'unimplemented.future-operation', AuthorizationScope::context('global')));
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    #[TestWith(['normal', 'metadata'])]
    #[TestWith(['normal', 'preview'])]
    #[TestWith(['normal', 'download'])]
    #[TestWith(['confidential', 'metadata'])]
    #[TestWith(['confidential', 'preview'])]
    #[TestWith(['confidential', 'download'])]
    #[TestWith(['highly_confidential', 'metadata'])]
    #[TestWith(['highly_confidential', 'preview'])]
    #[TestWith(['highly_confidential', 'download'])]
    public function test_all_document_classifications_and_operations_are_available_and_audited(string $classification, string $operation): void
    {
        $user = $this->administrator();
        $profile = DocumentSecurityProfile::factory()->inDepartment(Department::factory()->create())->classified(Confidentiality::from($classification))->create(['restricted' => true]);
        $permission = ['metadata' => 'documents.metadata.view', 'preview' => 'documents.content.preview', 'download' => 'documents.attachments.download'][$operation];

        $this->assertTrue(Gate::forUser($user)->allows($permission, DocumentSecurityDescriptor::fromProfile($profile)));

        $audit = DocumentSecurityAudit::query()->sole();
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($profile->id, $audit->profile_id);
        $this->assertSame('access.allowed', $audit->event);
        $this->assertSame($operation, $audit->operation);
        $this->assertSame('system_administrator', $audit->reason);
        $this->assertNull($audit->after);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('confidentiality_clearances', 0);
        $this->assertDatabaseCount('document_access_grants', 0);
    }

    #[TestWith(['scoped-role'])]
    #[TestWith(['ordinary-global-role'])]
    public function test_ordinary_administrators_do_not_gain_system_authority(string $kind): void
    {
        $this->definitions();
        $user = User::factory()->create();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();
        $scope = $profile->documentScope()->authorizationScope()->persist();
        OrganizationMembership::factory()->for($user)->atSite(Site::query()->findOrFail($scope->site_id))->create();
        if ($kind === 'scoped-role') {
            UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->id, 'scope_id' => $scope->id, 'active' => true]);
        } else {
            $this->grant($user, AuthorizationScope::context('global'), [...PermissionCatalog::ADMINISTRATOR_KEYS, 'documents.metadata.view', 'master-data.view']);
        }

        $this->assertFalse(app(AccessControl::class)->isSystemAdministrator($user));
        $this->assertFalse(app(DocumentAccess::class)->allows($user, DocumentSecurityDescriptor::fromProfile($profile), 'metadata'));
        $foreign = Site::factory()->create();
        $this->assertFalse(app(AccessControl::class)->allows($user, 'master-data.view', AuthorizationScope::context('site', $foreign->company_id, $foreign->id)));
    }

    #[TestWith(['assignment'])]
    #[TestWith(['role'])]
    #[TestWith(['user'])]
    public function test_revocation_is_observed_by_reused_actor_and_descriptor_instances(string $kind): void
    {
        $user = $this->administrator();
        $backup = User::factory()->create();
        $this->grant($backup, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create();
        $descriptor = DocumentSecurityDescriptor::fromProfile($profile);
        $user->load('roleAssignments.role.permissions');
        $access = app(AccessControl::class);
        $documents = app(DocumentAccess::class);
        $this->assertTrue($documents->allows($user, $descriptor, 'download'));

        match ($kind) {
            'assignment' => $user->roleAssignments()->sole()->update(['active' => false]),
            'role' => $user->roleAssignments()->sole()->role->update(['active' => false]),
            'user' => $user->deactivate(),
        };

        $this->assertFalse($access->isSystemAdministrator($user));
        $this->assertFalse($access->allows($user, 'numbering.manage', $profile->documentScope()->authorizationScope()));
        $this->assertFalse($documents->allows($user, $descriptor, 'download'));
        $this->assertSame('access.denied', DocumentSecurityAudit::query()->latest('id')->firstOrFail()->event);
    }

    public function test_system_authority_depends_on_role_identity_and_assignment_instead_of_permission_pivots(): void
    {
        $user = $this->administrator();
        $role = $user->roleAssignments()->sole()->role;
        $role->permissions()->detach();

        $this->assertTrue(app(AccessControl::class)->isSystemAdministrator($user));
        $this->assertTrue(app(AccessControl::class)->allows($user, 'numbering.manage', AuthorizationScope::context('global')));
    }

    public function test_system_administrator_can_manage_another_companys_document_security_without_membership(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $site = Site::factory()->create();
        OrganizationMembership::factory()->for($target)->atSite($site)->create();
        $profile = DocumentSecurityProfile::factory()->atSite($site)->classified(Confidentiality::HighlyConfidential)->create();

        $clearance = app(ManageDocumentSecurity::class)->grantClearance($actor, $target, $profile->documentScope(), Confidentiality::HighlyConfidential);

        $this->assertSame($target->id, $clearance->user_id);
        $this->assertSame(0, $actor->memberships()->count());
        $this->assertDatabaseHas('document_security_audits', ['actor_id' => $actor->id, 'target_user_id' => $target->id, 'event' => 'clearance.granted']);
    }

    public function test_ordinary_global_administrators_cannot_promote_revoke_or_modify_system_administrators(): void
    {
        $systemAdministrator = $this->administrator();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $scope = AuthorizationScope::context('global');
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $role = $systemAdministrator->roleAssignments()->sole()->role;
        $this->signIn($actor);

        $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $role->id, 'confirm_global' => true])->assertForbidden();
        $this->deleteJson('/api/v1/role-assignments/'.$systemAdministrator->roleAssignments()->sole()->id, $this->scopeData($scope))->assertForbidden();
        $this->patchJson('/api/v1/roles/'.$role->id, $this->scopeData($scope) + ['name_en' => 'Changed', 'name_lo' => 'ຕົວຢ່າງ', 'active' => true, 'permission_keys' => PermissionCatalog::ADMINISTRATOR_KEYS])->assertForbidden();
        $this->getJson($this->scopedUrl('roles/'.$role->id, $scope))->assertOk()->assertJsonPath('data.editable', false)->assertJsonPath('data.assignable', false);
        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $target->id]);
        $this->assertDatabaseCount('rbac_audits', 0);
    }

    public function test_system_administrator_can_explicitly_delegate_and_revoke_the_global_role_with_audits(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $scope = AuthorizationScope::context('global');
        $role = $actor->roleAssignments()->sole()->role;
        $this->signIn($actor);

        $id = $this->postJson('/api/v1/role-assignments', $this->scopeData($scope) + ['user_id' => $target->id, 'role_id' => $role->id, 'confirm_global' => true])->assertCreated()->json('data.id');
        $this->assertTrue(app(AccessControl::class)->isSystemAdministrator($target));
        $this->deleteJson('/api/v1/role-assignments/'.$id, $this->scopeData($scope))->assertNoContent();

        $this->assertFalse(app(AccessControl::class)->isSystemAdministrator($target));
        $this->assertSame(['assignment.granted', 'assignment.revoked'], DB::table('rbac_audits')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_forged_scope_id_cannot_promote_a_scoped_assignment_to_system_authority(): void
    {
        $systemAdministrator = $this->administrator();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        OrganizationMembership::factory()->for($target)->atSite($site)->create();
        $forgedScope = clone $scope;
        $forgedScope->id = AuthorizationScope::context('global')->id;

        $assignment = app(ManageRoles::class)->assign($actor, $forgedScope, $target, $systemAdministrator->roleAssignments()->sole()->role);

        $this->assertSame($scope->id, $assignment->scope_id);
        $this->assertFalse(app(AccessControl::class)->isSystemAdministrator($target));
    }

    public function test_a_forged_scope_id_cannot_allow_scoped_administrators_to_revoke_system_authority(): void
    {
        $systemAdministrator = $this->administrator();
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $this->grant($actor, $scope, PermissionCatalog::ADMINISTRATOR_KEYS);
        $forgedScope = clone $scope;
        $forgedScope->id = AuthorizationScope::context('global')->id;

        try {
            app(ManageRoles::class)->revoke($actor, $forgedScope, $systemAdministrator->roleAssignments()->sole());
            $this->fail('Scoped administration revoked a global System Administrator.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertTrue(app(AccessControl::class)->isSystemAdministrator($systemAdministrator));
    }

    #[TestWith(['cancel'])]
    #[TestWith(['throw'])]
    public function test_privileged_document_access_fails_closed_when_auditing_fails(string $failure): void
    {
        $user = $this->administrator();
        $descriptor = DocumentSecurityDescriptor::fromProfile(DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create());
        $event = 'eloquent.creating: '.DocumentSecurityAudit::class;
        Event::listen($event, function () use ($failure): bool {
            if ($failure === 'throw') {
                throw new RuntimeException('Audit unavailable');
            }

            return false;
        });

        try {
            app(DocumentAccess::class)->allows($user, $descriptor, 'download');
            $this->fail('Unaudited privileged access succeeded.');
        } catch (LogicException|RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('document_security_audits', 0);
    }

    #[TestWith(['metadata'])]
    #[TestWith(['preview'])]
    #[TestWith(['download'])]
    public function test_system_administrators_still_need_a_valid_active_resource(string $operation): void
    {
        $user = $this->administrator();
        $documents = app(DocumentAccess::class);
        $profile = DocumentSecurityProfile::factory()->create(['active' => false]);

        $this->assertFalse($documents->allows($user, null, $operation));
        $this->assertFalse($documents->allows($user, DocumentSecurityDescriptor::fromProfile($profile), $operation));
        $this->assertFalse($documents->allows($user, DocumentSecurityDescriptor::fromProfile($profile), 'unknown-operation'));
    }

    public function test_global_authority_does_not_bypass_authentication_session_or_csrf(): void
    {
        $actor = $this->administrator();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertUnauthorized();
        $this->signIn($actor);
        $this->withSession(['auth.session_version' => 'revoked-session']);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertUnauthorized();
        $this->signIn($actor);
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/document-master-data/registers', $this->scopeData($scope) + ['register_key' => 'incoming', 'active' => true])->assertStatus(419);
    }

    #[TestWith(['issued_document_numbers', 'update'])]
    #[TestWith(['issued_document_numbers', 'delete'])]
    #[TestWith(['numbering_configurations', 'update'])]
    #[TestWith(['document_security_audits', 'update'])]
    #[TestWith(['document_security_audits', 'delete'])]
    public function test_system_authority_cannot_modify_protected_history(string $table, string $operation): void
    {
        $user = $this->administrator();
        $register = RegisterConfiguration::factory()->create();
        $scope = AuthorizationScope::query()->findOrFail($register->scope_id);
        FormalRegistrationRecord::createTestTable();
        $series = app(ManageNumbering::class)->save($user, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'SYSTEM/TEST', 'padding' => 4, 'active' => true]);
        DB::transaction(fn () => app(DocumentNumbering::class)->register($user, $scope, $series->id, fn (string $number): FormalRegistrationRecord => FormalRegistrationRecord::query()->create(['scope_id' => $scope->id, 'register_id' => $register->id, 'state' => 'registered', 'internal_number' => $number])));
        app(DocumentAccess::class)->allows($user, DocumentSecurityDescriptor::fromProfile(DocumentSecurityProfile::factory()->create()), 'metadata');

        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table($table)->update(['created_at' => now()->addSecond()]);
        } else {
            DB::table($table)->delete();
        }
    }

    public function test_role_and_assignment_labels_are_bilingual_without_updating_stored_role_names(): void
    {
        $user = $this->administrator();
        $role = $user->roleAssignments()->sole()->role;
        $role->update(['name_en' => 'Legacy security label', 'name_lo' => 'ປ້າຍເກົ່າ']);
        $this->signIn($user);
        $scope = AuthorizationScope::context('global');

        $this->getJson($this->scopedUrl('roles/'.$role->id, $scope))->assertOk()->assertJsonPath('data.name_en', 'System Administrator')->assertJsonPath('data.name_lo', 'ຜູ້ບໍລິຫານລະບົບ');
        $this->getJson($this->scopedUrl('role-assignments', $scope))->assertOk()->assertJsonPath('data.0.role.name_en', 'System Administrator')->assertJsonPath('data.0.role.name_lo', 'ຜູ້ບໍລິຫານລະບົບ');
        $this->assertSame('Legacy security label', $role->refresh()->name_en);
    }
}
