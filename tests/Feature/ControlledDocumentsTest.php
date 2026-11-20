<?php

namespace Tests\Feature;

use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\ControlledDocument;
use App\Models\ControlledDocumentAudit;
use App\Models\ControlledRevision;
use App\Models\Department;
use App\Models\DocumentAccessGrant;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentType;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ControlledDocumentsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private User $actor;

    private AuthorizationScope $scope;

    private DocumentType $type;

    private Role $role;

    private function fixture(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26));
        config(['app.debug' => false]);
        $this->definitions();
        $site = Site::factory()->create();
        $this->scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $this->actor = User::factory()->create();
        OrganizationMembership::factory()->for($this->actor)->atSite($site)->create();
        $this->role = $this->grant($this->actor, $this->scope, ['controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage', 'documents.metadata.view', 'numbering.issue', 'numbering.manage']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $this->scope->id, 'register_key' => 'controlled']);
        $category = DocumentCategory::factory()->create(['scope_id' => $this->scope->id]);
        $this->type = DocumentType::factory()->create(['scope_id' => $this->scope->id, 'category_id' => $category->id]);
        $this->type->registers()->attach($register->id, ['scope_id' => $this->scope->id]);
        app(ManageNumbering::class)->save($this->actor, $this->scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/CD', 'padding' => 6, 'active' => true]);
        $this->signIn($this->actor);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/controlled-documents'.$suffix.'?'.http_build_query(['company_id' => $this->scope->company_id, 'site_id' => $this->scope->site_id]);
    }

    public function test_optional_draft_owner_can_be_assigned_later_but_is_required_for_release(): void
    {
        $this->fixture();
        $body = $this->payload();
        unset($body['owner_id']);
        $record = $this->postJson($this->url(), $body)->assertCreated()->assertJsonPath('data.revisions.0.owner_id', null)->json('data');
        $record = $this->registered($record);
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 2, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Release'])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $body = $this->payload();
        unset($body['classification']);
        $body['lock_version'] = 2;
        $this->patchJson($this->url('/'.$record['id']), $body)->assertUnprocessable();
        unset($body['document_code'], $body['type_id']);
        $record = $this->patchJson($this->url('/'.$record['id']), $body)->assertOk()->assertJsonPath('data.revisions.0.owner_id', $this->actor->id)->json('data');
        $this->released($record);
    }

    public function test_custodian_default_requires_exact_active_membership(): void
    {
        $this->fixture();
        $this->getJson($this->url('/lookups'))->assertOk()->assertJsonPath('default_custodian_id', $this->actor->id);
        $department = Department::factory()->create(['company_id' => $this->scope->company_id, 'site_id' => $this->scope->site_id]);
        OrganizationMembership::factory()->for($this->actor)->inDepartment($department)->create();
        $this->getJson($this->url('/lookups').'&department_id='.$department->id)->assertOk()->assertJsonPath('default_custodian_id', $this->actor->id);
        $outsider = User::factory()->create();
        $this->postJson($this->url(), array_replace($this->payload(), ['custodian_id' => $outsider->id]))->assertUnprocessable()->assertJsonValidationErrors('custodian_id');
    }

    public function test_site_document_controller_can_create_a_department_draft_with_a_valid_custodian(): void
    {
        $this->fixture();
        $this->actor->memberships()->sole()->update(['all_departments' => true]);
        $department = Department::factory()->for($this->scope->site)->create();
        $role = Role::query()->where('code', Role::DOCUMENT_CONTROLLER_CODE)->sole();
        UserRoleAssignment::factory()->for($this->actor)->for($role)->for($this->scope, 'scope')->create();
        $query = '&department_id='.$department->id;

        $this->getJson($this->url('/lookups').$query)->assertOk()->assertJsonPath('default_custodian_id', $this->actor->id);
        $this->postJson($this->url().$query, $this->payload())->assertCreated()
            ->assertJsonPath('data.revisions.0.custodian_id', $this->actor->id);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['document_code' => 'XPPL/SOP/001', 'type_id' => $this->type->id, 'classification' => 'normal', 'title_en' => 'Safety procedure', 'title_lo' => 'ລະບຽບຄວາມປອດໄພ',
            'owner_id' => $this->actor->id, 'custodian_id' => $this->actor->id, 'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'review_date' => '2027-10-26',
            'revision_reason' => 'Initial issue', 'change_description' => 'Initial procedure'];
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function draft(array $changes = []): array
    {
        return $this->postJson($this->url(), array_replace($this->payload(), $changes))->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function registered(array $record): array
    {
        return $this->postJson($this->url('/'.$record['id'].'/register'), ['lock_version' => $record['lock_version']])->assertOk()->json('data');
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function released(array $record): array
    {
        $draft = collect($record['revisions'])->firstWhere('status', 'draft');

        return $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => $record['lock_version'], 'revision_id' => $draft['id'], 'reason' => 'Authorized release'])->assertOk()->json('data');
    }

    public function test_draft_edit_and_formal_registration_use_existing_numbering_without_releasing(): void
    {
        $this->fixture();
        $record = $this->draft();
        $this->assertNull($record['internal_number']);
        $this->assertNull($record['authoritative_revision_id']);
        $this->assertSame('R00', $record['revisions'][0]['identifier']);
        $this->assertDatabaseCount('numbering_counters', 0);
        $body = $this->payload();
        unset($body['classification']);
        $body['lock_version'] = 1;
        $body['title_en'] = 'Updated draft';
        $record = $this->patchJson($this->url('/'.$record['id']), $body)->assertOk()->assertJsonPath('data.revisions.0.title_en', 'Updated draft')->json('data');
        $record = $this->registered($record);
        $this->assertSame('TEST/CD/2026/000001', $record['internal_number']);
        $this->assertSame('draft', $record['status']);
        $this->assertNull($record['authoritative_revision_id']);
        $this->assertDatabaseCount('controlled_registration_links', 1);
        $this->postJson($this->url('/'.$record['id'].'/register'), ['lock_version' => $record['lock_version']])->assertUnprocessable();
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->getJson($this->url('/lookups'))->assertOk()->assertJsonPath('people.0.id', $this->actor->id);
    }

    public function test_workflow_lookups_and_draft_validation_exclude_types_not_mapped_to_controlled(): void
    {
        $this->fixture();
        $outgoing = RegisterConfiguration::factory()->create(['scope_id' => $this->scope->id, 'register_key' => 'outgoing']);
        $otherType = DocumentType::factory()->create(['scope_id' => $this->scope->id, 'category_id' => $this->type->category_id]);
        $otherType->registers()->attach($outgoing->id, ['scope_id' => $this->scope->id]);

        $this->getJson($this->url('/lookups'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->type->id);
        $this->postJson($this->url(), array_replace($this->payload(), ['type_id' => $otherType->id]))->assertUnprocessable()->assertJsonPath('errors.type_id.0', 'controlledInvalidType');
        $this->assertDatabaseCount('controlled_documents', 0);
    }

    public function test_revision_release_preserves_authority_until_atomic_replacement_and_snapshots_history(): void
    {
        $this->fixture();
        $record = $this->released($this->registered($this->draft()));
        $first = $record['authoritative_revision_id'];
        $record = $this->postJson($this->url('/'.$record['id'].'/revisions'), ['lock_version' => $record['lock_version'], 'revision_reason' => 'Annual review', 'change_description' => 'Updated precautions'])->assertOk()->assertJsonPath('data.authoritative_revision_id', $first)->assertJsonPath('data.revisions.1.identifier', 'R01')->json('data');
        $this->assertSame($first, $record['revisions'][1]['supersedes_id']);
        $this->postJson($this->url('/'.$record['id'].'/revisions'), ['lock_version' => $record['lock_version'], 'revision_reason' => 'Duplicate', 'change_description' => 'Duplicate'])->assertUnprocessable();
        $body = $this->payload();
        unset($body['classification'], $body['document_code'], $body['type_id']);
        $body['lock_version'] = $record['lock_version'];
        $body['title_en'] = 'Revised safety procedure';
        $record = $this->patchJson($this->url('/'.$record['id']), $body)->assertOk()->json('data');
        $record = $this->released($record);
        $this->assertSame($record['revisions'][1]['id'], $record['authoritative_revision_id']);
        $this->assertSame('superseded', $record['revisions'][0]['status']);
        $this->assertSame('Safety procedure', $record['revisions'][0]['title_en']);
        $this->assertSame('Revised safety procedure', $record['revisions'][1]['title_en']);
        $this->assertSame($this->actor->id, $record['revisions'][1]['released_by']);
        $this->assertNotNull($record['revisions'][1]['released_at']);
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->getJson($this->url('/'.$record['id']))->assertOk()->assertJsonPath('audits.5.action', 'released')->assertJsonPath('audits.5.reason', 'Authorized release');
    }

    public function test_released_revision_identity_and_audit_are_immutable_through_models_and_sql(): void
    {
        $this->fixture();
        $record = $this->released($this->registered($this->draft()));
        foreach ([fn () => DB::table('controlled_revisions')->where('id', $record['current_revision_id'])->update(['title_en' => 'FORGED']),
            fn () => DB::table('controlled_revisions')->delete(), fn () => DB::table('controlled_document_audits')->delete(),
            fn () => DB::table('controlled_registration_links')->delete(), fn () => DB::table('controlled_documents')->where('id', $record['id'])->update(['document_code' => 'FORGED', 'lock_version' => 4]),
            fn () => DB::table('controlled_documents')->where('id', $record['id'])->update(['current_revision_id' => null, 'lock_version' => 4]),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Protected controlled history was changed.');
            } catch (QueryException) {
            }
        }
        $this->expectException(ValidationException::class);
        ControlledDocument::findOrFail($record['id'])->currentRevision->update(['title_en' => 'FORGED']);
    }

    #[TestWith(['LEGACY/SOP/001'])]
    #[TestWith(['EXAMPLE-ADM-SOP-0001-R00'])]
    public function test_revision_labels_advance_without_rewriting_identity_history_or_allocating_another_number(string $code): void
    {
        $this->fixture();
        $series = NumberingSeries::query()->sole();
        app(ManageNumbering::class)->save($this->actor, $this->scope, ['prefix' => 'TEST/CD', 'padding' => 6, 'active' => true,
            'format' => ['pattern' => '{prefix}/{year}/{sequence}-R00']], (string) $series->id);
        $record = $this->released($this->registered($this->draft(['document_code' => $code])));
        $id = $record['id'];
        $number = $record['internal_number'];
        $this->assertSame('TEST/CD/2026/000001-R00', $number);
        $original = ControlledRevision::findOrFail($record['revisions'][0]['id'])->getRawOriginal();
        $issuance = IssuedDocumentNumber::query()->sole()->getRawOriginal();
        for ($revision = 1; $revision <= 2; $revision++) {
            $record = $this->postJson($this->url('/'.$id.'/revisions'), ['lock_version' => $record['lock_version'],
                'revision_reason' => 'Periodic review', 'change_description' => 'Updated content'])->assertOk()->json('data');
            $this->assertSame($id, $record['id']);
            $this->assertSame($code, $record['document_code']);
            $this->assertSame($number, $record['internal_number']);
            $this->assertSame($revision === 1 ? 'R01' : 'R02', $record['revisions'][$revision]['identifier']);
            $this->assertSame($code === 'LEGACY/SOP/001' ? $code.($revision === 1 ? '-R01' : '-R02') : null, $record['revisions'][$revision]['display_identifier']);
            $record = $this->released($record);
        }
        $this->getJson($this->url('/'.$id))->assertOk()->assertJsonPath('data.revisions.0.identifier', 'R00')
            ->assertJsonPath('data.revisions.1.identifier', 'R01')->assertJsonPath('data.revisions.2.identifier', 'R02')
            ->assertJsonPath('data.revisions.0.status', 'superseded')->assertJsonPath('data.revisions.2.status', 'current');
        $this->assertSame($code === 'LEGACY/SOP/001' ? 'LEGACY/SOP/001-R00' : $code, $record['revisions'][0]['display_identifier']);
        $this->assertSame($original, ControlledRevision::findOrFail($original['id'])->getRawOriginal());
        $this->assertSame($issuance, IssuedDocumentNumber::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertSame(1, DB::table('numbering_counters')->sole()->value);
    }

    public function test_stale_edits_wrong_revision_and_unregistered_release_cannot_change_authority(): void
    {
        $this->fixture();
        $record = $this->draft();
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 1, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Too soon'])->assertUnprocessable();
        $record = $this->registered($record);
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 1, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Stale'])->assertUnprocessable()->assertJsonPath('errors.controlled.0', 'controlledStale');
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 2, 'revision_id' => 999999, 'reason' => 'Wrong revision'])->assertUnprocessable();
        $record = $this->released($record);
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 3, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Duplicate'])->assertUnprocessable();
        $this->assertDatabaseCount('controlled_document_audits', 3);
    }

    public function test_supersession_obsolescence_and_archive_preserve_released_history_and_valid_relationships(): void
    {
        $this->fixture();
        $first = $this->released($this->registered($this->draft()));
        $second = $this->released($this->registered($this->draft(['document_code' => 'XPPL/SOP/002'])));
        $first = $this->postJson($this->url('/'.$first['id'].'/status'), ['lock_version' => 3, 'status' => 'superseded', 'replaced_by_id' => $second['id'], 'reason' => 'Replaced by consolidated procedure'])->assertOk()->assertJsonPath('data.authoritative_revision_id', null)->json('data');
        $this->getJson($this->url('/'.$first['id']))->assertOk()->assertJsonPath('related.0.id', $second['id'])->assertJsonPath('related.0.relationship', 'replacement');
        $this->postJson($this->url('/'.$first['id'].'/status'), ['lock_version' => $first['lock_version'], 'status' => 'obsolete', 'reason' => 'Invalid'])->assertUnprocessable();
        $this->postJson($this->url('/'.$first['id'].'/status'), ['lock_version' => $first['lock_version'], 'status' => 'archived', 'reason' => 'Retention archive'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson($this->url('/'.$second['id'].'/status'), ['lock_version' => 3, 'status' => 'obsolete', 'reason' => 'No longer used'])->assertOk()->assertJsonPath('data.authoritative_revision_id', null);
        $this->getJson($this->url().'&status=obsolete')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('controlled_revisions', 2);
    }

    #[TestWith(['controlled.register', 'register'])]
    #[TestWith(['numbering.issue', 'register'])]
    #[TestWith(['controlled.release', 'release'])]
    #[TestWith(['controlled.status.manage', 'status'])]
    #[TestWith(['controlled.drafts.manage', 'revisions'])]
    public function test_each_write_requires_its_permission(string $permission, string $action): void
    {
        $this->fixture();
        $record = $this->released($this->registered($this->draft()));
        $this->role->permissions()->detach(Permission::query()->where('key', $permission)->value('id'));
        $this->postJson($this->url('/'.$record['id'].'/'.$action), ['lock_version' => $record['lock_version']])->assertForbidden();
        $this->assertDatabaseCount('controlled_document_audits', 3);
    }

    #[TestWith(['audit'])]
    #[TestWith(['ledger'])]
    public function test_failed_registration_rolls_back_counter_identity_and_history(string $boundary): void
    {
        $this->fixture();
        $record = $this->draft();
        $event = 'eloquent.creating: '.($boundary === 'audit' ? ControlledDocumentAudit::class : IssuedDocumentNumber::class);
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url('/'.$record['id'].'/register'), ['lock_version' => 1])->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseHas('controlled_documents', ['id' => $record['id'], 'lock_version' => 1, 'internal_number' => null]);
        $this->registered($record);
    }

    public function test_release_audit_failure_restores_previous_authority_and_editable_draft(): void
    {
        $this->fixture();
        $record = $this->released($this->registered($this->draft()));
        $record = $this->postJson($this->url('/'.$record['id'].'/revisions'), ['lock_version' => 3, 'revision_reason' => 'Review', 'change_description' => 'Update'])->assertOk()->json('data');
        $event = 'eloquent.creating: '.ControlledDocumentAudit::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 4, 'revision_id' => $record['revisions'][1]['id'], 'reason' => 'Release'])->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('controlled_documents', ['id' => $record['id'], 'current_revision_id' => $record['current_revision_id'], 'lock_version' => 4]);
        $this->assertDatabaseHas('controlled_revisions', ['id' => $record['revisions'][1]['id'], 'released_at' => null, 'draft_slot' => 1]);
        $this->released($record);
    }

    public function test_confidentiality_hides_lists_details_history_and_replacement_metadata(): void
    {
        $this->fixture();
        $adminRole = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $this->actor->id, 'role_id' => $adminRole->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $first = $this->released($this->registered($this->draft()));
        $secret = $this->released($this->registered($this->draft(['document_code' => 'SECRET/001', 'classification' => 'highly_confidential'])));
        $this->postJson($this->url('/'.$first['id'].'/status'), ['lock_version' => 3, 'status' => 'superseded', 'replaced_by_id' => $secret['id'], 'reason' => 'Replacement'])->assertOk();
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($this->scope->site)->create();
        $this->grant($reader, $this->scope, ['documents.metadata.view', 'controlled.release']);
        $this->signIn($reader);
        $this->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 1)->assertDontSee('SECRET/001');
        $this->getJson($this->url('/'.$secret['id']))->assertNotFound();
        $this->getJson($this->url('/'.$first['id']))->assertOk()->assertJsonCount(0, 'related')->assertDontSee('SECRET/001')->assertJsonMissingPath('data.replaced_by_id');
        $profile = ControlledDocument::findOrFail($secret['id'])->profile;
        ConfidentialityClearance::factory()->forProfile($profile)->create(['user_id' => $reader->id]);
        $this->getJson($this->url('/'.$secret['id']))->assertNotFound();
        DocumentAccessGrant::factory()->create(['profile_id' => $profile->id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->url('/'.$secret['id']))->assertOk()->assertJsonCount(1, 'data.revisions');
        $this->assertTrue(DocumentSecurityAudit::query()->where('reason', 'system_administrator')->exists());
    }

    public function test_exact_membership_master_applicability_and_people_are_revalidated(): void
    {
        $this->fixture();
        $record = $this->draft();
        $this->type->update(['active' => false]);
        $this->postJson($this->url('/'.$record['id'].'/register'), ['lock_version' => 1])->assertUnprocessable();
        $this->type->update(['active' => true]);
        $record = $this->registered($record);
        OrganizationMembership::query()->where('user_id', $this->actor->id)->update(['active' => false]);
        $this->getJson($this->url())->assertForbidden();
        UserRoleAssignment::query()->create(['user_id' => $this->actor->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->value('id'), 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->getJson($this->url('/lookups'))->assertOk()->assertJsonCount(0, 'people')->assertJsonPath('default_custodian_id', null);
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 2, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Release'])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }

    #[TestWith([['internal_number' => 'FORGED']])]
    #[TestWith([['effective_date' => '2026-10-24']])]
    #[TestWith([['owner_id' => 999999]])]
    #[TestWith([['document_code' => 'invalid code']])]
    public function test_invalid_drafts_do_not_persist_profiles_or_consume_numbers(array $changes): void
    {
        $this->fixture();
        $this->postJson($this->url(), array_replace($this->payload(), $changes))->assertUnprocessable();
        $this->assertDatabaseCount('controlled_documents', 0);
        $this->assertDatabaseCount('document_security_profiles', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_future_effective_revision_cannot_become_authoritative_early(): void
    {
        $this->fixture();
        $record = $this->registered($this->draft(['effective_date' => '2026-11-01']));
        $this->postJson($this->url('/'.$record['id'].'/release'), ['lock_version' => 2, 'revision_id' => $record['revisions'][0]['id'], 'reason' => 'Too early'])
            ->assertUnprocessable()->assertJsonPath('errors.effective_date.0', 'controlledEffectiveFuture');
        $this->assertDatabaseHas('controlled_documents', ['id' => $record['id'], 'current_revision_id' => null, 'lock_version' => 2]);
    }

    public function test_department_membership_foreign_records_types_and_owners_do_not_cross_contexts(): void
    {
        $this->fixture();
        $record = $this->draft();
        $department = Department::factory()->create(['company_id' => $this->scope->company_id, 'site_id' => $this->scope->site_id]);
        $body = $this->payload();
        $body['department_id'] = $department->id;
        $body['document_code'] = 'DEPT/SOP/001';
        $this->postJson($this->url(), $body)->assertForbidden();
        OrganizationMembership::factory()->for($this->actor)->inDepartment($department)->create();
        $deptRecord = $this->postJson($this->url(), $body)->assertCreated()->json('data');
        $this->getJson($this->url('/'.$deptRecord['id']))->assertNotFound();
        $this->getJson($this->url('/'.$record['id']).'&department_id='.$department->id)->assertNotFound();
        $foreign = Site::factory()->create();
        $foreignScope = AuthorizationScope::context('site', $foreign->company_id, $foreign->id)->persist();
        OrganizationMembership::factory()->for($this->actor)->atSite($foreign)->create();
        $this->grant($this->actor, $foreignScope, ['documents.metadata.view', 'controlled.drafts.manage']);
        $this->getJson('/api/v1/controlled-documents/'.$record['id'].'?'.http_build_query(['company_id' => $foreign->company_id, 'site_id' => $foreign->id]))->assertNotFound();
        $body = $this->payload();
        $body['document_code'] = 'FOREIGN/OWNER';
        $body['owner_id'] = User::factory()->create()->id;
        $this->postJson($this->url(), $body)->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $foreignType = DocumentType::factory()->create();
        $body['owner_id'] = $this->actor->id;
        $body['type_id'] = $foreignType->id;
        $this->postJson($this->url(), $body)->assertUnprocessable()->assertJsonValidationErrors('type_id');
    }

    public function test_database_guards_reject_duplicate_draft_slots_foreign_revision_pointers_and_authority_rollback(): void
    {
        $this->fixture();
        $record = $this->released($this->registered($this->draft()));
        $second = $this->released($this->registered($this->draft(['document_code' => 'XPPL/SOP/002'])));
        $record = $this->postJson($this->url('/'.$record['id'].'/revisions'), ['lock_version' => 3, 'revision_reason' => 'Review', 'change_description' => 'Update'])->assertOk()->json('data');
        $record = $this->released($record);
        foreach ([$record['revisions'][0]['id'], $second['current_revision_id']] as $invalidPointer) {
            try {
                DB::table('controlled_documents')->where('id', $record['id'])->update(['current_revision_id' => $invalidPointer, 'lock_version' => 6]);
                $this->fail('Invalid authoritative revision was accepted.');
            } catch (QueryException) {
            }
        }
        $factoryDraft = ControlledRevision::factory()->create();
        try {
            ControlledRevision::factory()->create(['document_id' => $factoryDraft->document_id]);
            $this->fail('A second initial draft was accepted.');
        } catch (QueryException) {
        }
        $this->assertDatabaseHas('controlled_documents', ['id' => $record['id'], 'current_revision_id' => $record['current_revision_id'], 'lock_version' => 5]);
    }

    public function test_guest_and_read_only_requests_cannot_create_or_learn_restricted_records(): void
    {
        $this->getJson('/api/v1/controlled-documents/contexts')->assertUnauthorized();
        $this->fixture();
        $this->role->permissions()->detach(Permission::where('key', 'controlled.drafts.manage')->value('id'));
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->getJson($this->url().'&status=unknown')->assertUnprocessable();
        $this->assertDatabaseCount('controlled_documents', 0);
    }
}
