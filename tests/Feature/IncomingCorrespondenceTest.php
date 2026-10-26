<?php

namespace Tests\Feature;

use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\IssuedDocumentNumber;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class IncomingCorrespondenceTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function fixture(array $permissions = ['incoming.drafts.manage', 'documents.metadata.view', 'incoming.register', 'numbering.issue', 'numbering.manage']): array
    {
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, $scope, $permissions);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $this->signIn($user);

        return [$user, $scope, $register, $type];
    }

    private function payload(AuthorizationScope $scope, DocumentType $type, array $extra = []): array
    {
        return array_replace(['company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'type_id' => $type->id,
            'subject' => 'Synthetic incoming subject', 'sender_organization' => 'Example sender', 'sender_contact' => 'Example contact',
            'external_reference' => 'REPEATABLE/EXTERNAL', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26',
            'description_lo' => 'ເອກະສານທົດສອບ', 'priority' => 'normal', 'channel' => 'email', 'classification' => 'normal'], $extra);
    }

    private function url(AuthorizationScope $scope, string $suffix = '', array $extra = []): string
    {
        return '/api/v1/incoming-correspondence'.$suffix.'?'.http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id] + $extra);
    }

    private function series(User $user, AuthorizationScope $scope, RegisterConfiguration $register): void
    {
        app(ManageNumbering::class)->save($user, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/IN', 'padding' => 6, 'active' => true]);
    }

    public function test_draft_creation_binds_security_and_audit_without_consuming_numbers(): void
    {
        [, $scope, , $type] = $this->fixture();
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->assertCreated()->assertJsonPath('data.internal_number', null)->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->getJson($this->url($scope, '/'.$id))->assertOk()->assertJsonPath('data.description_lo', 'ເອກະສານທົດສອບ')->assertJsonPath('audits.0.action', 'created');
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('document_security_profiles', 1);
        $this->assertDatabaseCount('incoming_correspondence_audits', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_draft_editing_keeps_identity_and_updates_audits(): void
    {
        [, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        unset($body['classification']);
        $body['subject'] = 'Revised draft';
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertOk()->assertJsonPath('data.subject', 'Revised draft');
        $this->assertSame(['subject'], array_values(array_diff(IncomingCorrespondenceAudit::query()->latest('id')->first()->changed_fields, ['updated_at'])));
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    #[TestWith([['internal_number' => 'FORGED', 'status' => 'registered', 'profile_id' => 123, 'scope_id' => 123]])]
    #[TestWith([['subject' => '', 'sender_email' => 'invalid', 'received_date' => '2026-02-31', 'priority' => 'unknown', 'classification' => 'unknown']])]
    #[TestWith([['type_id' => 99999]])]
    #[TestWith([['correspondence_date' => '03/04/2026', 'received_date' => '04/03/2026']])]
    public function test_rejects_invalid_or_server_controlled_draft_fields(array $invalid): void
    {
        [, $scope, , $type] = $this->fixture();
        $this->postJson($this->url($scope), $this->payload($scope, $type, $invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        $this->assertDatabaseCount('incoming_correspondences', 0);
        $this->assertDatabaseCount('document_security_profiles', 0);
    }

    public function test_registration_is_atomic_and_duplicate_registration_consumes_nothing(): void
    {
        [$user, $scope, $register, $type] = $this->fixture();
        $this->travelTo(now()->setDate(2026, 10, 26));
        $this->series($user, $scope, $register);
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk()->assertJsonPath('data.internal_number', 'TEST/IN/2026/000001')->assertJsonPath('data.external_reference', 'REPEATABLE/EXTERNAL')->assertJsonPath('data.registered_by', $user->id);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertUnprocessable()->assertJsonValidationErrors('incoming');
        unset($body['classification']);
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertUnprocessable();
        $this->assertDatabaseCount('incoming_registration_links', 1);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertSame(1, (int) DB::table('numbering_counters')->value('value'));
        $this->assertSame(['created', 'registered'], IncomingCorrespondenceAudit::query()->orderBy('id')->pluck('action')->all());
    }

    #[TestWith(['audit'])]
    #[TestWith(['ledger'])]
    public function test_registration_persistence_failure_rolls_back_state_counter_and_history(string $failure): void
    {
        [$user, $scope, $register, $type] = $this->fixture();
        $this->series($user, $scope, $register);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        $event = $failure === 'audit' ? 'eloquent.creating: '.IncomingCorrespondenceAudit::class : 'eloquent.creating: '.IssuedDocumentNumber::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope, '/'.$id.'/register'))->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('incoming_correspondences', ['id' => $id, 'status' => 'draft', 'internal_number' => null]);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('incoming_registration_links', 0);
        $this->assertDatabaseCount('incoming_correspondence_audits', 1);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk();
        $this->assertSame(1, (int) DB::table('numbering_counters')->value('value'));
    }

    #[TestWith(['type'])]
    #[TestWith(['category'])]
    #[TestWith(['register'])]
    public function test_registration_rechecks_current_master_availability(string $kind): void
    {
        [$user, $scope, $register, $type] = $this->fixture();
        $this->series($user, $scope, $register);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        $model = match ($kind) {
            'type' => $type, 'category' => $type->category, 'register' => $register
        };
        $model->update(['active' => false]);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertUnprocessable();
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->getJson($this->url($scope, '/'.$id))->assertOk();
    }

    public function test_database_retains_type_applicability_and_registered_history(): void
    {
        [$user, $scope, $register, $type] = $this->fixture();
        $this->series($user, $scope, $register);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        try {
            $type->registers()->detach();
            $this->fail('Referenced applicability was removed.');
        } catch (QueryException) {
        }
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk();
        foreach ([fn () => DB::table('incoming_correspondences')->where('id', $id)->update(['internal_number' => 'FORGED']),
            fn () => DB::table('incoming_correspondences')->where('id', $id)->delete(),
            fn () => DB::table('incoming_correspondence_audits')->delete(),
            fn () => DB::table('incoming_registration_links')->delete()] as $change) {
            try {
                $change();
                $this->fail('Protected history was changed.');
            } catch (QueryException) {
            }
        }
    }

    #[TestWith(['incoming.drafts.manage'])]
    #[TestWith(['documents.metadata.view'])]
    public function test_creation_requires_both_workflow_permission_and_metadata_access(string $missing): void
    {
        [, $scope, , $type] = $this->fixture(array_values(array_diff(['incoming.drafts.manage', 'documents.metadata.view'], [$missing])));
        $this->postJson($this->url($scope), $this->payload($scope, $type))->assertForbidden();
        $this->assertDatabaseCount('incoming_correspondences', 0);
    }

    public function test_cross_scope_records_and_types_are_hidden(): void
    {
        [, $scope, , $type] = $this->fixture();
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        [, $other, , $otherType] = $this->fixture();
        $this->getJson($this->url($other, '/'.$id))->assertNotFound();
        $this->getJson($this->url($other))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->url($other), $this->payload($other, $type))->assertUnprocessable()->assertJsonValidationErrors('type_id');
        $this->getJson($this->url($scope, '/'.$id))->assertForbidden();
    }

    public function test_exact_department_membership_and_clearance_protect_metadata_and_mutations(): void
    {
        [$user, $scope, , $type] = $this->fixture();
        $department = Department::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        $body = $this->payload($scope, $type, ['department_id' => $department->id]);
        $this->postJson($this->url($scope), $body)->assertForbidden();
        OrganizationMembership::factory()->for($user)->inDepartment($department)->create();
        $id = $this->postJson($this->url($scope), $body)->assertCreated()->json('data.id');
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        $this->getJson($this->url($scope, '/'.$id, ['department_id' => $department->id]))->assertOk();
        $body['classification'] = 'confidential';
        $this->postJson($this->url($scope), $body)->assertForbidden();
        ConfidentialityClearance::factory()->create(['user_id' => $user->id, 'company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'department_id' => $department->id, 'classification' => 'confidential']);
        $secretId = $this->postJson($this->url($scope), $body)->assertCreated()->json('data.id');
        ConfidentialityClearance::query()->update(['active' => false]);
        $this->getJson($this->url($scope, '/'.$secretId, ['department_id' => $department->id]))->assertNotFound();
        $this->getJson($this->url($scope, '', ['department_id' => $department->id]))->assertOk()->assertJsonPath('meta.total', 1);
        unset($body['classification']);
        $this->patchJson($this->url($scope, '/'.$secretId), $body)->assertNotFound();
    }

    public function test_global_system_administrator_can_register_highly_confidential_without_membership_and_is_audited(): void
    {
        [$user, $scope, $register, $type] = $this->fixture([]);
        OrganizationMembership::query()->delete();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->series($user, $scope, $register);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type, ['classification' => 'highly_confidential']))->assertCreated()->json('data.id');
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk();
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertTrue(DocumentSecurityAudit::query()->where('reason', 'system_administrator')->exists());
    }

    public function test_unauthenticated_api_is_rejected(): void
    {
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertUnauthorized();
        $this->postJson('/api/v1/incoming-correspondence', [])->assertUnauthorized();
    }

    #[TestWith(['incoming.register'])]
    #[TestWith(['numbering.issue'])]
    #[TestWith(['documents.metadata.view'])]
    public function test_registration_requires_each_permission_inside_the_transition(string $missing): void
    {
        [$actor, $scope, $register, $type] = $this->fixture();
        $this->series($actor, $scope, $register);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        $role = $actor->roleAssignments()->with('role')->first()->role;
        $role->permissions()->detach(Permission::query()->where('key', $missing)->value('id'));
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertForbidden();
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseHas('incoming_correspondences', ['id' => $id, 'status' => 'draft']);
    }

    public function test_revoked_membership_blocks_discovery_detail_and_mutations(): void
    {
        [, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        OrganizationMembership::query()->update(['active' => false]);
        $this->getJson($this->url($scope))->assertForbidden();
        $this->getJson($this->url($scope, '/'.$id))->assertForbidden();
        unset($body['classification']);
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertForbidden();
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertForbidden();
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_workflow_lookups_require_no_catalog_management_and_exclude_inapplicable_types(): void
    {
        [, $scope, $register, $type] = $this->fixture(['documents.metadata.view', 'incoming.drafts.manage']);
        $otherRegister = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $otherType = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $type->category_id]);
        $otherType->registers()->attach($otherRegister->id, ['scope_id' => $scope->id]);
        $this->getJson($this->url($scope, '/lookups'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $type->id);
        $this->postJson($this->url($scope), $this->payload($scope, $otherType))->assertUnprocessable()->assertJsonPath('errors.type_id.0', 'incomingInvalidType');
        $this->getJson($this->scopedUrl('document-master-data/types', $scope))->assertForbidden();
    }

    public function test_security_identity_and_registration_fields_cannot_be_supplied_on_mutations(): void
    {
        [$actor, $scope, $register, $type] = $this->fixture();
        $this->series($actor, $scope, $register);
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertUnprocessable()->assertJsonValidationErrors('classification');
        $this->postJson($this->url($scope, '/'.$id.'/register'), ['internal_number' => 'FORGED'])->assertUnprocessable()->assertJsonValidationErrors('internal_number');
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    public function test_operational_audit_cancellation_rolls_back_draft_creation_and_update(): void
    {
        [, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $event = 'eloquent.creating: '.IncomingCorrespondenceAudit::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url($scope), $body)->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('incoming_correspondences', 0);
        $this->assertDatabaseCount('document_security_profiles', 0);
        $this->assertDatabaseCount('document_security_audits', 0);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        unset($body['classification']);
        $body['subject'] = 'Must roll back';
        app('events')->listen($event, fn () => false);
        try {
            $this->patchJson($this->url($scope, '/'.$id), $body)->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('incoming_correspondences', ['id' => $id, 'subject' => 'Synthetic incoming subject']);
        $this->assertDatabaseCount('incoming_correspondence_audits', 1);
    }

    public function test_highly_confidential_requires_both_exact_clearance_and_document_allowlist(): void
    {
        [$admin, $scope, $register, $type] = $this->fixture();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $admin->id, 'role_id' => $role->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type, ['classification' => 'highly_confidential']))->json('data.id');
        $profile = IncomingCorrespondence::query()->findOrFail($id)->profile;
        $this->series($admin, $scope, $register);
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($scope->site)->create();
        $this->grant($reader, $scope, ['documents.metadata.view', 'incoming.drafts.manage', 'incoming.register', 'numbering.issue']);
        $this->signIn($reader);
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        ConfidentialityClearance::factory()->forProfile($profile)->create(['user_id' => $reader->id]);
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        $grant = DocumentAccessGrant::factory()->create(['profile_id' => $profile->id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->url($scope, '/'.$id))->assertOk();
        $grant->update(['active' => false]);
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertNotFound();
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_external_references_can_repeat_while_internal_numbers_remain_distinct(): void
    {
        [$actor, $scope, $register, $type] = $this->fixture();
        $this->series($actor, $scope, $register);
        $numbers = [];
        for ($index = 0; $index < 2; $index++) {
            $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->assertCreated()->json('data.id');
            $numbers[] = $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk()->assertJsonPath('data.external_reference', 'REPEATABLE/EXTERNAL')->json('data.internal_number');
        }
        $this->assertNotSame($numbers[0], $numbers[1]);
        $this->assertSame(2, (int) DB::table('numbering_counters')->value('value'));
    }

    public function test_department_only_members_can_manage_drafts_but_cannot_issue_site_numbers(): void
    {
        [$actor, $scope, $register, $type] = $this->fixture();
        $this->series($actor, $scope, $register);
        $department = Department::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        OrganizationMembership::query()->delete();
        OrganizationMembership::factory()->for($actor)->inDepartment($department)->create();
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type, ['department_id' => $department->id]))->assertCreated()->json('data.id');
        $contexts = $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertNotContains('numbering.issue', $contexts[0]['permissions']);
        $this->postJson($this->url($scope, '/'.$id.'/register', ['department_id' => $department->id]))->assertForbidden();
        $this->assertDatabaseCount('numbering_counters', 0);
    }
}
