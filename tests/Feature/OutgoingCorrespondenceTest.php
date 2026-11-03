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
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
use App\Models\OrganizationMembership;
use App\Models\OutgoingCorrespondence;
use App\Models\OutgoingCorrespondenceAudit;
use App\Models\OutgoingRecipient;
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

class OutgoingCorrespondenceTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @param list<string> $purposes
     * @return array{User, AuthorizationScope, RegisterConfiguration, DocumentType, Role}
     */
    private function fixture(array $purposes = ['internal_register', 'official_outgoing']): array
    {
        $this->travelTo(now()->setDate(2026, 10, 26));
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $role = $this->grant($actor, $scope, ['outgoing.drafts.manage', 'outgoing.register', 'documents.metadata.view', 'numbering.manage', 'numbering.issue']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        foreach ($purposes as $purpose) {
            app(ManageNumbering::class)->save($actor, $scope, $this->seriesData($register, $purpose));
        }
        $this->signIn($actor);

        return [$actor, $scope, $register, $type, $role];
    }

    /** @return array<string, mixed> */
    private function seriesData(RegisterConfiguration $register, string $purpose): array
    {
        return ['register_id' => $register->id, 'purpose' => $purpose, 'reset_policy' => 'annual', 'prefix' => $purpose === 'internal_register' ? 'TEST/OUT' : 'TEST/OFFICIAL', 'padding' => 6, 'active' => true];
    }

    /** @return array<string, mixed> */
    private function payload(AuthorizationScope $scope, DocumentType $type): array
    {
        return ['company_id' => $scope->company_id, 'site_id' => $scope->site_id, 'type_id' => $type->id,
            'signatory_name' => 'Example signatory', 'signatory_title' => 'Example title', 'subject' => 'Synthetic outgoing subject',
            'description_lo' => 'ເອກະສານທົດສອບ', 'document_date' => '2026-10-25', 'priority' => 'normal', 'classification' => 'normal',
            'recipients' => [['kind' => 'primary', 'organization' => 'Example recipient', 'email' => 'recipient@example.test'], ['kind' => 'primary', 'organization' => 'Second recipient'], ['kind' => 'copy', 'organization' => 'Copy recipient']]];
    }

    private function url(AuthorizationScope $scope, string $suffix = ''): string
    {
        return '/api/v1/outgoing-correspondence'.$suffix.'?'.http_build_query(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
    }

    public function test_drafts_capture_multiple_primary_and_copy_recipients_and_edit_without_consuming_numbers(): void
    {
        [, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->assertCreated()->assertJsonPath('data.internal_number', null)->assertJsonPath('data.official_number', null)->assertJsonCount(3, 'data.recipients')->json('data.id');
        unset($body['classification']);
        $body['subject'] = 'Revised draft';
        $body['recipients'] = [['kind' => 'primary', 'organization' => 'Reassigned recipient', 'address' => 'Example postal address']];
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertOk()->assertJsonCount(1, 'data.recipients')->assertJsonPath('data.recipients.0.organization', 'Reassigned recipient');
        $this->getJson($this->url($scope, '/'.$id))->assertOk()->assertJsonPath('data.description_lo', 'ເອກະສານທົດສອບ')->assertJsonPath('audits.1.action', 'updated');
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('outgoing_recipients', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertContains('recipients', OutgoingCorrespondenceAudit::query()->latest('id')->first()->changed_fields);
    }

    public function test_registration_issues_two_distinct_numbers_atomically_and_protects_identity_recipients_and_history(): void
    {
        [$actor, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk()->assertJsonPath('data.internal_number', 'TEST/OUT/2026/000001')->assertJsonPath('data.official_number', 'TEST/OFFICIAL/2026/000001')->assertJsonPath('data.registered_by', $actor->id);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertUnprocessable()->assertJsonPath('errors.outgoing.0', 'outgoingAlreadyRegistered');
        unset($body['classification']);
        $this->patchJson($this->url($scope, '/'.$id), $body)->assertUnprocessable();
        foreach ([fn () => DB::table('outgoing_correspondences')->where('id', $id)->update(['official_number' => 'FORGED']),
            fn () => DB::table('outgoing_recipients')->where('correspondence_id', $id)->delete(),
            fn () => DB::table('outgoing_recipients')->where('correspondence_id', $id)->update(['organization' => 'FORGED']),
            fn () => DB::table('outgoing_recipients')->insert(['correspondence_id' => $id, 'kind' => 'copy', 'organization' => 'FORGED']),
            fn () => DB::table('outgoing_correspondence_audits')->delete(), fn () => DB::table('outgoing_registration_links')->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Registered outgoing identity or history was changed.');
            } catch (QueryException) {
            }
        }
        $this->assertDatabaseCount('issued_document_numbers', 2);
        $this->assertDatabaseCount('outgoing_registration_links', 2);
        $this->assertSame([1, 1], DB::table('numbering_counters')->orderBy('series_id')->pluck('value')->map(fn (mixed $value): int => (int) $value)->all());
        $this->assertSame(['created', 'registered'], OutgoingCorrespondenceAudit::query()->orderBy('id')->pluck('action')->all());
    }

    #[TestWith(['audit'])]
    #[TestWith(['official_ledger'])]
    #[TestWith(['internal_ledger'])]
    public function test_registration_failure_rolls_back_both_counters_identity_and_all_issuance_history(string $boundary): void
    {
        [, $scope, , $type] = $this->fixture();
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        $event = 'eloquent.creating: '.($boundary === 'audit' ? OutgoingCorrespondenceAudit::class : IssuedDocumentNumber::class);
        $calls = 0;
        app('events')->listen($event, function () use (&$calls, $boundary): ?bool {
            $calls++;

            return $calls === ($boundary === 'internal_ledger' ? 2 : 1) ? false : null;
        });
        try {
            $this->postJson($this->url($scope, '/'.$id.'/register'))->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $id, 'status' => 'draft', 'internal_number' => null, 'official_number' => null]);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('outgoing_registration_links', 0);
        $this->assertDatabaseCount('outgoing_correspondence_audits', 1);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk()->assertJsonPath('data.official_number', 'TEST/OFFICIAL/2026/000001');
    }

    #[TestWith(['missing'])]
    #[TestWith(['inactive'])]
    #[TestWith(['type'])]
    public function test_both_required_series_and_current_master_applicability_are_checked_before_registration(string $kind): void
    {
        [$actor, $scope, $register, $type] = $this->fixture($kind === 'missing' ? ['internal_register'] : ['internal_register', 'official_outgoing']);
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        if ($kind === 'inactive') {
            $series = NumberingSeries::query()->where('register_id', $register->id)->where('purpose', 'official_outgoing')->sole();
            app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'TEST/OFFICIAL', 'padding' => 6, 'active' => false, 'change_reason' => 'Test disabled series'], (string) $series->id);
        } elseif ($kind === 'type') {
            $type->update(['active' => false]);
        }
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertUnprocessable();
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    #[TestWith([['recipients' => [['kind' => 'copy', 'organization' => 'Only copies']]], 'recipients'])]
    #[TestWith([['recipients' => [['kind' => 'primary', 'organization' => '', 'email' => 'invalid', 'id' => 99]]], 'recipients.0'])]
    #[TestWith([['document_date' => '03/04/2026'], 'document_date'])]
    #[TestWith([['internal_number' => 'FORGED', 'official_number' => 'FORGED', 'status' => 'registered'], 'official_number'])]
    public function test_invalid_recipients_and_client_supplied_numbers_are_rejected_without_persistence(array $invalid, string $field): void
    {
        [, $scope, , $type] = $this->fixture();
        $this->postJson($this->url($scope), array_replace($this->payload($scope, $type), $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('outgoing_correspondences', 0);
        $this->assertDatabaseCount('document_security_profiles', 0);
    }

    public function test_recipient_persistence_failure_restores_the_previous_draft_and_audit(): void
    {
        [, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $id = $this->postJson($this->url($scope), $body)->json('data.id');
        unset($body['classification']);
        $body['subject'] = 'Must not persist';
        $event = 'eloquent.creating: '.OutgoingRecipient::class;
        app('events')->listen($event, fn () => false);
        try {
            $this->patchJson($this->url($scope, '/'.$id), $body)->assertInternalServerError();
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseHas('outgoing_correspondences', ['id' => $id, 'subject' => 'Synthetic outgoing subject']);
        $this->assertDatabaseCount('outgoing_recipients', 3);
        $this->assertDatabaseCount('outgoing_correspondence_audits', 1);
    }

    #[TestWith(['outgoing.register'])]
    #[TestWith(['numbering.issue'])]
    #[TestWith(['documents.metadata.view'])]
    public function test_registration_requires_each_operational_permission(string $missing): void
    {
        [, $scope, , $type, $role] = $this->fixture();
        $id = $this->postJson($this->url($scope), $this->payload($scope, $type))->json('data.id');
        $role->permissions()->detach(Permission::query()->where('key', $missing)->value('id'));
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertForbidden();
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertDatabaseCount('numbering_counters', 0);
    }

    public function test_exact_department_membership_cross_site_records_and_master_data_are_enforced(): void
    {
        [$actor, $scope, , $type] = $this->fixture();
        $body = $this->payload($scope, $type);
        $department = Department::factory()->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        $body['department_id'] = $department->id;
        $this->postJson($this->url($scope), $body)->assertForbidden();
        OrganizationMembership::factory()->for($actor)->inDepartment($department)->create();
        $id = $this->postJson($this->url($scope), $body)->assertCreated()->json('data.id');
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        $this->getJson($this->url($scope, '/'.$id).'&department_id='.$department->id)->assertOk();
        [, $foreign, , $foreignType] = $this->fixture([]);
        $this->getJson($this->url($foreign, '/'.$id))->assertNotFound();
        $this->postJson($this->url($foreign), $this->payload($foreign, $type))->assertUnprocessable()->assertJsonValidationErrors('type_id');
        $this->getJson($this->url($foreign, '/lookups'))->assertOk()->assertJsonPath('data.0.id', $foreignType->id);
    }

    public function test_workflow_lookups_and_draft_validation_exclude_types_not_mapped_to_outgoing(): void
    {
        [, $scope, , $type] = $this->fixture();
        $incoming = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
        $otherType = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $type->category_id]);
        $otherType->registers()->attach($incoming->id, ['scope_id' => $scope->id]);

        $this->getJson($this->url($scope, '/lookups'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $type->id);
        $this->postJson($this->url($scope), $this->payload($scope, $otherType))->assertUnprocessable()->assertJsonPath('errors.type_id.0', 'outgoingInvalidType');
        $this->assertDatabaseCount('outgoing_correspondences', 0);
    }

    public function test_highly_confidential_access_requires_clearance_and_allowlist_while_global_administrator_is_audited(): void
    {
        [$admin, $scope, , $type] = $this->fixture();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $admin->id, 'role_id' => $role->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        OrganizationMembership::query()->update(['active' => false]);
        $id = $this->postJson($this->url($scope), array_replace($this->payload($scope, $type), ['classification' => 'highly_confidential']))->assertCreated()->json('data.id');
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertOk();
        $this->assertTrue(DocumentSecurityAudit::query()->where('reason', 'system_administrator')->exists());
        $reader = User::factory()->create();
        OrganizationMembership::factory()->for($reader)->atSite($scope->site)->create();
        $this->grant($reader, $scope, ['documents.metadata.view', 'outgoing.drafts.manage', 'outgoing.register', 'numbering.issue']);
        $this->signIn($reader);
        $profile = OutgoingCorrespondence::query()->findOrFail($id)->profile;
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        ConfidentialityClearance::factory()->forProfile($profile)->create(['user_id' => $reader->id]);
        $this->getJson($this->url($scope, '/'.$id))->assertNotFound();
        $grant = DocumentAccessGrant::factory()->create(['profile_id' => $profile->id, 'user_id' => $reader->id, 'operation' => 'metadata']);
        $this->getJson($this->url($scope, '/'.$id))->assertOk();
        $grant->update(['active' => false]);
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->url($scope, '/'.$id.'/register'))->assertNotFound();
    }

    public function test_unauthenticated_and_read_only_users_cannot_create_drafts(): void
    {
        $this->getJson('/api/v1/outgoing-correspondence/contexts')->assertUnauthorized();
        [, $scope, , $type, $role] = $this->fixture();
        $role->permissions()->detach(Permission::query()->where('key', 'outgoing.drafts.manage')->value('id'));
        $this->postJson($this->url($scope), $this->payload($scope, $type))->assertForbidden();
        $this->assertDatabaseCount('outgoing_correspondences', 0);
    }

    public function test_register_search_filters_subject_and_recipient_before_counting(): void
    {
        [, $scope, , $type] = $this->fixture();
        $this->postJson($this->url($scope), array_replace($this->payload($scope, $type), ['subject' => 'Unique search subject']))->assertCreated();
        $this->postJson($this->url($scope), array_replace($this->payload($scope, $type), ['subject' => 'Different subject']))->assertCreated();

        $this->getJson($this->url($scope).'&search=Unique%20search')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.subject', 'Unique search subject');
        $this->getJson($this->url($scope).'&search=Example%20recipient')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($scope).'&search=No%20matching%20document')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }
}
