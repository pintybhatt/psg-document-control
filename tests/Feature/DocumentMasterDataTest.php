<?php

namespace Tests\Feature;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentAccess;
use App\Authorization\DocumentSecurityDescriptor;
use App\Authorization\InitializeRoleDefinitions;
use App\Authorization\ManageDocumentMasterData;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentMasterDataTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, OrganizationMembership} */
    private function administrator(array $keys = ['master-data.view', 'master-data.manage']): array
    {
        $this->definitions();
        config(['app.debug' => false]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        $membership = OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, $scope, $keys);
        $this->signIn($user);

        return [$user, $scope, $membership];
    }

    private function endpoint(string $kind, AuthorizationScope $scope): string
    {
        return $this->scopedUrl('document-master-data/'.$kind, $scope);
    }

    private function categoryData(): array
    {
        return ['code' => 'GENERAL', 'name_en' => 'General', 'name_lo' => 'ທົ່ວໄປ', 'description_en' => 'Example', 'description_lo' => 'ຕົວຢ່າງ', 'active' => true];
    }

    /** @return array{DocumentCategory, RegisterConfiguration} */
    private function parents(AuthorizationScope $scope): array
    {
        return [DocumentCategory::factory()->create(['scope_id' => $scope->id]), RegisterConfiguration::factory()->create(['scope_id' => $scope->id])];
    }

    public function test_bilingual_categories_types_and_registers_can_be_created_edited_and_audited(): void
    {
        [$actor, $scope] = $this->administrator();
        $data = $this->scopeData($scope);
        $categoryId = $this->postJson('/api/v1/document-master-data/categories', $data + $this->categoryData())->assertCreated()->json('data.id');
        $registerId = $this->postJson('/api/v1/document-master-data/registers', $data + ['register_key' => 'incoming', 'active' => true])->assertCreated()->json('data.id');
        $typeId = $this->postJson('/api/v1/document-master-data/types', $data + $this->categoryData() + ['category_id' => $categoryId, 'register_ids' => [$registerId]])->assertCreated()->assertJsonPath('data.effective_registers', ['incoming'])->json('data.id');
        foreach (['categories' => $categoryId, 'types' => $typeId, 'registers' => $registerId] as $kind => $id) {
            $this->patchJson('/api/v1/document-master-data/'.$kind.'/'.$id, $data + ['description_lo' => 'ຄຳອະທິບາຍໃໝ່'])->assertOk()->assertJsonPath('data.description_lo', 'ຄຳອະທິບາຍໃໝ່');
        }
        $this->getJson($this->endpoint('types', $scope))->assertOk()->assertJsonPath('data.0.category.name_lo', 'ທົ່ວໄປ');
        $this->assertDatabaseHas('document_type_register', ['type_id' => $typeId, 'register_id' => $registerId, 'scope_id' => $scope->id]);
        $this->assertDatabaseCount('master_data_audits', 6);
        $audit = MasterDataAudit::query()->latest('id')->first();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($scope->id, $audit->scope_id);
        $this->assertSame('updated', $audit->action);
        $this->assertNull($audit->before['description_lo']);
        $this->assertSame('ຄຳອະທິບາຍໃໝ່', $audit->after['description_lo']);
    }

    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    #[TestWith(['controlled'])]
    public function test_register_identities_are_fixed_and_unique_per_scope(string $identity): void
    {
        [, $scope] = $this->administrator();
        $data = $this->scopeData($scope) + ['register_key' => $identity, 'active' => true];
        $this->postJson('/api/v1/document-master-data/registers', $data)->assertCreated();
        $this->postJson('/api/v1/document-master-data/registers', $data)->assertUnprocessable()->assertJsonValidationErrors('register_key');
        $this->postJson('/api/v1/document-master-data/registers', array_replace($data, ['register_key' => 'custom-workflow']))->assertUnprocessable()->assertJsonValidationErrors('register_key');
    }

    public function test_codes_are_unique_in_the_exact_scope_and_validation_never_reveals_foreign_relationships(): void
    {
        [, $scope] = $this->administrator();
        $foreign = DocumentCategory::factory()->create(['code' => 'GENERAL']);
        $foreignRegister = RegisterConfiguration::factory()->create(['scope_id' => $foreign->scope_id]);
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertCreated();
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertUnprocessable()->assertJsonValidationErrors('code');
        [$category, $register] = $this->parents($scope);
        foreach (['category_id' => $foreign->id, 'register_ids' => [$foreignRegister->id]] as $field => $value) {
            $payload = $this->scopeData($scope) + $this->categoryData() + ['category_id' => $category->id, 'register_ids' => [$register->id]];
            $existing = $this->postJson('/api/v1/document-master-data/types', array_replace($payload, [$field => $value]))->assertUnprocessable();
            $missing = $this->postJson('/api/v1/document-master-data/types', array_replace($payload, [$field => is_array($value) ? [999999] : 999999]))->assertUnprocessable();
            $this->assertSame($existing->json(), $missing->json());
        }
    }

    #[TestWith(['code', 'bad code'])]
    #[TestWith(['name_en', ''])]
    #[TestWith(['name_lo', ''])]
    #[TestWith(['active', 'yes'])]
    #[TestWith(['description_lo', ['invalid']])]
    public function test_invalid_category_fields_are_rejected(string $field, mixed $value): void
    {
        [, $scope] = $this->administrator();
        $this->postJson('/api/v1/document-master-data/categories', array_replace($this->scopeData($scope) + $this->categoryData(), [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('document_categories', 0);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    public function test_type_applicability_is_required_distinct_and_editable(): void
    {
        [, $scope] = $this->administrator();
        [$category, $register] = $this->parents($scope);
        $payload = $this->scopeData($scope) + $this->categoryData() + ['category_id' => $category->id];
        $this->postJson('/api/v1/document-master-data/types', $payload)->assertUnprocessable()->assertJsonValidationErrors('register_ids');
        $this->postJson('/api/v1/document-master-data/types', $payload + ['register_ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/document-master-data/types', $payload + ['register_ids' => [$register->id, $register->id]])->assertUnprocessable();
        $id = $this->postJson('/api/v1/document-master-data/types', $payload + ['register_ids' => [$register->id]])->assertCreated()->json('data.id');
        $outgoing = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $this->patchJson('/api/v1/document-master-data/types/'.$id, $this->scopeData($scope) + ['register_ids' => [$outgoing->id]])->assertOk()->assertJsonPath('data.effective_registers', ['outgoing']);
        $this->assertDatabaseMissing('document_type_register', ['type_id' => $id, 'register_id' => $register->id]);
    }

    #[TestWith(['categories'])]
    #[TestWith(['registers'])]
    public function test_deactivation_immediately_blocks_type_applicability_and_reactivation(string $kind): void
    {
        [, $scope] = $this->administrator();
        [$category, $register] = $this->parents($scope);
        $type = DocumentType::factory()->create(['category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $this->assertTrue($type->effectiveFor($register));
        $id = $kind === 'categories' ? $category->id : $register->id;
        $this->patchJson('/api/v1/document-master-data/'.$kind.'/'.$id, $this->scopeData($scope) + ['active' => false])->assertOk();
        $this->assertFalse($type->effectiveFor($register));
        $this->getJson($this->endpoint('types', $scope))->assertOk()->assertJsonPath('data.0.effective', false);
        $this->patchJson('/api/v1/document-master-data/types/'.$type->id, $this->scopeData($scope) + ['active' => false])->assertOk();
        $this->patchJson('/api/v1/document-master-data/types/'.$type->id, $this->scopeData($scope) + ['active' => true])->assertUnprocessable();
        $this->patchJson('/api/v1/document-master-data/'.$kind.'/'.$id, $this->scopeData($scope) + ['active' => true])->assertOk();
        $this->patchJson('/api/v1/document-master-data/types/'.$type->id, $this->scopeData($scope) + ['active' => true])->assertOk();
        $this->assertTrue($type->effectiveFor($register));
    }

    public function test_unauthenticated_and_unprivileged_users_cannot_use_any_master_endpoint(): void
    {
        $this->getJson('/api/v1/document-master-data/categories')->assertUnauthorized();
        $this->postJson('/api/v1/document-master-data/categories', [])->assertUnauthorized();
        [, $scope] = $this->administrator([]);
        foreach (['categories', 'types', 'registers', 'classifications'] as $kind) {
            $this->getJson($this->endpoint($kind, $scope))->assertForbidden();
        }
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertForbidden();
        $this->patchJson('/api/v1/document-master-data/categories/999', $this->scopeData($scope) + ['active' => false])->assertForbidden();
    }

    #[TestWith(['role-only'])]
    #[TestWith(['membership-only'])]
    #[TestWith(['department-only'])]
    #[TestWith(['central-admin'])]
    public function test_role_and_exact_operational_membership_are_both_required(string $scenario): void
    {
        $keys = in_array($scenario, ['membership-only', 'central-admin'], true) ? [] : ['master-data.view'];
        [$actor, $scope, $membership] = $this->administrator($keys);
        if ($scenario === 'role-only') {
            $membership->update(['active' => false]);
        }
        if ($scenario === 'department-only') {
            $membership->update(['active' => false]);
            OrganizationMembership::factory()->for($actor)->inDepartment(Department::factory()->for(Site::query()->findOrFail($scope->site_id))->create())->create();
        }
        if ($scenario === 'central-admin') {
            $this->grant($actor, AuthorizationScope::context('global'), PermissionCatalog::ADMINISTRATOR_KEYS);
        }
        $this->getJson($this->endpoint('categories', $scope))->assertForbidden();
    }

    public function test_company_role_requires_separate_company_or_site_membership_and_never_merges_master_scopes(): void
    {
        [$actor, $siteScope] = $this->administrator([]);
        $companyScope = AuthorizationScope::context('company', $siteScope->company_id)->persist();
        $this->grant($actor, $companyScope, ['master-data.view', 'master-data.manage']);
        DocumentCategory::factory()->create(['scope_id' => $companyScope->id]);
        $this->getJson($this->endpoint('categories', $companyScope))->assertForbidden();
        $this->getJson($this->endpoint('categories', $siteScope))->assertOk()->assertJsonCount(0, 'data');
        OrganizationMembership::factory()->for($actor)->create(['company_id' => $companyScope->company_id]);
        $this->getJson($this->endpoint('categories', $companyScope))->assertOk()->assertJsonCount(1, 'data');
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_cross_site_and_company_requests_and_direct_record_updates_are_denied(bool $sameCompany): void
    {
        [, $scope] = $this->administrator();
        $site = $sameCompany ? Site::factory()->create(['company_id' => $scope->company_id]) : Site::factory()->create();
        $foreignScope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $foreign = DocumentCategory::factory()->create(['scope_id' => $foreignScope->id]);
        $this->getJson($this->endpoint('categories', $foreignScope))->assertForbidden();
        $data = $this->scopeData($scope) + ['active' => false];
        $existing = $this->patchJson('/api/v1/document-master-data/categories/'.$foreign->id, $data)->assertNotFound();
        $missing = $this->patchJson('/api/v1/document-master-data/categories/999999', $data)->assertNotFound();
        $this->assertSame($existing->json(), $missing->json());
        $this->assertTrue($foreign->refresh()->active);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    #[TestWith(['membership'])]
    #[TestWith(['role'])]
    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['user'])]
    public function test_revocation_and_inactive_records_deny_existing_sessions_immediately(string $kind): void
    {
        [$actor, $scope, $membership] = $this->administrator();
        $this->getJson($this->endpoint('categories', $scope))->assertOk();
        match ($kind) {
            'membership' => $membership->update(['active' => false]),
            'role' => $actor->roleAssignments()->sole()->update(['active' => false]),
            'company' => $scope->company->update(['active' => false]),
            'site' => $scope->site->update(['active' => false]),
            'user' => $actor->deactivate(),
        };
        $this->getJson($this->endpoint('categories', $scope))->assertStatus($kind === 'user' ? 401 : 403);
    }

    public function test_read_permission_never_allows_mutation_and_master_permissions_never_grant_document_access(): void
    {
        [$actor, $scope] = $this->administrator(['master-data.view']);
        $this->getJson($this->endpoint('categories', $scope))->assertOk();
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertForbidden();
        $profile = DocumentSecurityProfile::factory()->classified(Confidentiality::HighlyConfidential)->create(['company_id' => $scope->company_id, 'site_id' => $scope->site_id]);
        $this->assertFalse(app(DocumentAccess::class)->allows($actor, DocumentSecurityDescriptor::fromProfile($profile), 'metadata'));
        $this->getJson($this->endpoint('classifications', $scope))->assertOk()->assertJsonPath('data.0.value', 'normal')->assertJsonPath('data.2.value', 'highly_confidential')->assertJsonCount(3, 'data');
    }

    public function test_stable_identifiers_category_ownership_and_audit_fields_cannot_be_forged(): void
    {
        [, $scope] = $this->administrator();
        [$category, $register] = $this->parents($scope);
        $type = DocumentType::factory()->create(['category_id' => $category->id]);
        foreach (['code' => 'NEW_CODE', 'scope_id' => $scope->id, 'created_by' => 1, 'updated_by' => 1, 'actor_id' => 1, 'category_id' => $category->id, 'classification' => 'normal'] as $field => $value) {
            $this->patchJson('/api/v1/document-master-data/types/'.$type->id, $this->scopeData($scope) + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/api/v1/document-master-data/registers/'.$register->id, $this->scopeData($scope) + ['register_key' => 'outgoing'])->assertUnprocessable();
        $this->deleteJson('/api/v1/document-master-data/categories/'.$category->id, $this->scopeData($scope))->assertStatus(405);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    #[TestWith(['category'])]
    #[TestWith(['register'])]
    public function test_composite_foreign_keys_reject_cross_scope_relationships(string $kind): void
    {
        $foreign = DocumentCategory::factory()->create();
        $type = DocumentType::factory()->create();
        $this->expectException(QueryException::class);
        if ($kind === 'category') {
            DB::table('document_types')->where('id', $type->id)->update(['category_id' => $foreign->id]);
        } else {
            $register = RegisterConfiguration::factory()->create(['scope_id' => $foreign->scope_id]);
            DB::table('document_type_register')->insert(['type_id' => $type->id, 'register_id' => $register->id, 'scope_id' => $type->scope_id]);
        }
    }

    public function test_referenced_category_cannot_be_deleted_in_the_database(): void
    {
        $type = DocumentType::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('document_categories')->where('id', $type->category_id)->delete();
    }

    public function test_master_data_model_identity_is_immutable_and_delete_requires_deactivation_instead(): void
    {
        $category = DocumentCategory::factory()->create();
        try {
            $category->update(['code' => 'CHANGED']);
            $this->fail('Stable code changed.');
        } catch (ValidationException) {
            $this->assertNotSame('CHANGED', $category->refresh()->code);
        }
        $this->expectException(ValidationException::class);
        $category->delete();
    }

    public function test_audit_write_failure_rolls_back_the_master_change(): void
    {
        [, $scope] = $this->administrator();
        $event = 'eloquent.creating: '.MasterDataAudit::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Test audit outage');
        });
        try {
            app(ManageDocumentMasterData::class)->save(auth()->user(), $scope, 'categories', $this->categoryData());
            $this->fail('Unaudited data was saved.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test audit outage', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('document_categories', 0);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_audit_history_rejects_database_mutations(string $operation): void
    {
        [, $scope] = $this->administrator();
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertCreated();
        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table('master_data_audits')->update(['action' => 'updated']);
        } else {
            DB::table('master_data_audits')->delete();
        }
    }

    #[TestWith(['categories'])]
    #[TestWith(['types'])]
    #[TestWith(['registers'])]
    public function test_database_uniqueness_protects_master_identity(string $kind): void
    {
        $category = DocumentCategory::factory()->create();
        $record = match ($kind) {
            'categories' => $category,
            'types' => DocumentType::factory()->create(['category_id' => $category->id]),
            'registers' => RegisterConfiguration::factory()->create(['scope_id' => $category->scope_id]),
        };
        $copy = $record->getAttributes();
        unset($copy['id']);
        $this->expectException(QueryException::class);
        DB::table($record->getTable())->insert($copy);
    }

    public function test_database_rejects_custom_register_identity(): void
    {
        $register = RegisterConfiguration::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('register_configurations')->where('id', $register->id)->update(['register_key' => 'custom-workflow']);
    }

    public function test_global_and_forged_organization_contexts_never_authorize_master_data(): void
    {
        [$actor, $scope] = $this->administrator();
        $this->grant($actor, AuthorizationScope::context('global'), ['master-data.view', 'master-data.manage']);
        $this->getJson($this->endpoint('categories', AuthorizationScope::context('global')))->assertForbidden();
        $foreign = Site::factory()->create();
        $this->postJson('/api/v1/document-master-data/categories', array_replace($this->scopeData($scope) + $this->categoryData(), ['company_id' => $foreign->company_id]))->assertForbidden();
        $this->getJson('/api/v1/document-master-data/unknown')->assertNotFound();
        $this->postJson('/api/v1/document-master-data/classifications', $this->scopeData($scope))->assertStatus(405);
    }

    public function test_outer_rollback_removes_master_mutation_and_its_transactional_audit(): void
    {
        [$actor, $scope] = $this->administrator();
        DB::beginTransaction();
        try {
            app(ManageDocumentMasterData::class)->save($actor, $scope, 'categories', $this->categoryData());
            $this->assertDatabaseCount('master_data_audits', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('document_categories', 0);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    public function test_mutations_require_csrf_and_catalog_initialization_grants_nothing(): void
    {
        [, $scope] = $this->administrator();
        app(InitializeRoleDefinitions::class)->run();
        $template = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        $this->assertFalse($template->permissions()->where('key', 'master-data.manage')->exists());
        $this->assertDatabaseCount('document_categories', 0);
        $this->assertDatabaseCount('register_configurations', 0);
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/document-master-data/categories', $this->scopeData($scope) + $this->categoryData())->assertStatus(419);
    }
}
