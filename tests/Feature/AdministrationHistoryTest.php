<?php

namespace Tests\Feature;

use App\Authorization\AdministrationHistory;
use App\Authorization\ManageDocumentMasterData;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\MembershipAudit;
use App\Models\OrganizationMembership;
use App\Models\RbacAudit;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AdministrationHistoryTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function url(AuthorizationScope $scope, string $source = 'master-data'): string
    {
        return $this->scopedUrl('administration/history', $scope).'&source='.$source;
    }

    /** @return array{User, AuthorizationScope, OrganizationMembership} */
    private function administrator(string $scopeType = 'site', array $permissions = ['security.audit.view', 'master-data.view', 'master-data.manage']): array
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context($scopeType, $site->company_id, $scopeType === 'site' ? $site->id : null)->persist();
        $actor = User::factory()->create();
        $membership = OrganizationMembership::factory()->for($actor)->create(['membership_type' => $scopeType, 'company_id' => $site->company_id, 'site_id' => $scope->site_id]);
        $this->grant($actor, $scope, $permissions);
        $this->signIn($actor);

        return [$actor, $scope, $membership];
    }

    #[TestWith(['site'])]
    #[TestWith(['company'])]
    public function test_scoped_administrators_see_audited_changes_in_the_exact_scope(string $scopeType): void
    {
        [$actor, $scope] = $this->administrator($scopeType);
        $this->freezeTime();
        $record = app(ManageDocumentMasterData::class)->save($actor, $scope, 'categories', ['code' => 'TEST', 'name_en' => 'Test category', 'name_lo' => 'ຕົວຢ່າງ', 'active' => true]);
        app(ManageDocumentMasterData::class)->save($actor, $scope, 'categories', ['active' => false], (string) $record->id);

        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'updated')->assertJsonPath('data.0.actor', $actor->name)
            ->assertJsonPath('data.0.changes.0', ['field' => 'active', 'before' => true, 'after' => false])
            ->assertJsonPath('sources', ['master-data']);
        $this->getJson($this->url($scope).'&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'created');
        $this->assertDatabaseHas('document_categories', ['id' => $record->id, 'active' => false]);
    }

    public function test_review_policy_history_describes_changes_without_rewriting_evidence_or_other_metadata_events(): void
    {
        [$actor, $scope] = $this->administrator();
        $this->freezeTime();
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'controlled']);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $manager = app(ManageDocumentMasterData::class);
        $manager->save($actor, $scope, 'types', ['review_enabled' => true, 'review_interval_months' => 36, 'review_baseline' => 'completion_date'], (string) $type->id);
        $evidence = MasterDataAudit::query()->sole()->getAttributes();
        $row = $this->getJson($this->url($scope))->assertOk()->assertJsonPath('data.0.event_en', 'Document review policy changed')
            ->assertJsonPath('data.0.event_lo', 'ປ່ຽນນະໂຍບາຍທົບທວນເອກະສານ')->assertJsonPath('data.0.action', 'updated')->assertJsonPath('data.0.actor', $actor->name)->json('data.0');
        $this->assertSame(['review_enabled', 'review_interval_months', 'review_baseline'], array_column($row['changes'], 'field'));
        $this->assertSame(['field' => 'review_enabled', 'before' => false, 'after' => true], $row['changes'][0]);
        $this->assertSame($evidence, MasterDataAudit::query()->sole()->getAttributes());
        $manager->save($actor, $scope, 'types', ['name_en' => 'Renamed type', 'review_enabled' => '1', 'review_interval_months' => '36', 'review_baseline' => 'completion_date'], (string) $type->id);
        $this->getJson($this->url($scope))->assertOk()->assertJsonPath('data.0.event_en', null)->assertJsonPath('data.0.event_lo', null)->assertJsonPath('data.0.action', 'updated');
    }

    public function test_history_requires_authentication_and_both_audit_and_source_permissions(): void
    {
        $this->getJson('/api/v1/administration/history')->assertUnauthorized();
        [$actor, $scope] = $this->administrator('site', ['master-data.view']);
        $this->getJson($this->url($scope))->assertForbidden();
        $this->grant($actor, $scope, ['security.audit.view']);
        $this->getJson($this->url($scope))->assertOk();
        $this->getJson($this->url($scope, 'numbering'))->assertForbidden();
        $this->getJson($this->url($scope, 'roles'))->assertForbidden();
        $this->getJson($this->url($scope, 'invalid'))->assertUnprocessable();
        $this->getJson($this->url($scope).'&per_page=1000')->assertUnprocessable();
        $this->postJson('/api/v1/administration/history', $this->scopeData($scope))->assertStatus(405);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_foreign_company_and_site_history_is_forbidden(bool $sameCompany): void
    {
        [, $scope] = $this->administrator();
        $foreignSite = Site::factory()->create($sameCompany ? ['company_id' => $scope->company_id] : []);
        $foreignScope = AuthorizationScope::context('site', $foreignSite->company_id, $foreignSite->id)->persist();
        MasterDataAudit::query()->create(['actor_id' => auth()->id(), 'scope_id' => $foreignScope->id, 'subject_type' => 'categories', 'subject_id' => 999, 'action' => 'created', 'after' => ['name_en' => 'Foreign secret']]);
        $this->getJson($this->url($foreignScope))->assertForbidden();
        $this->getJson($this->url($scope))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revoked_membership_denies_history_in_an_existing_session(): void
    {
        [, $scope, $membership] = $this->administrator();
        $this->getJson($this->url($scope))->assertOk();
        $membership->update(['active' => false]);
        $this->getJson($this->url($scope))->assertForbidden();
    }

    public function test_global_system_administrator_can_review_each_source_without_membership_and_snapshots_are_redacted(): void
    {
        [$actor, $scope, $membership] = $this->administrator('site', []);
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->id, 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $membership->update(['active' => false]);
        MasterDataAudit::query()->create(['actor_id' => $actor->id, 'scope_id' => $scope->id, 'subject_type' => 'categories', 'subject_id' => 123, 'action' => 'updated', 'before' => ['active' => true, 'token' => 'SECRET'], 'after' => ['active' => false, 'token' => 'SECRET']]);
        $this->getJson($this->url($scope))->assertOk()->assertJsonCount(1, 'data')->assertDontSee('SECRET')->assertJsonMissingPath('data.0.before');
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
        app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'TEST/HISTORY', 'padding' => 6, 'active' => false]);
        $this->getJson($this->url($scope, 'numbering'))->assertOk()->assertJsonPath('data.0.action', 'configured');
        MembershipAudit::query()->create(['membership_id' => $membership->id, 'actor_id' => $actor->id, 'target_user_id' => $actor->id, 'action' => 'updated', 'before' => ['active' => true], 'after' => ['active' => false]]);
        $this->getJson($this->url($scope, 'memberships'))->assertOk()->assertJsonCount(1, 'data');
        $global = AuthorizationScope::context('global');
        RbacAudit::query()->create(['actor_id' => $actor->id, 'source' => 'web', 'scope_id' => $global->id, 'subject_type' => 'role', 'subject_id' => 1, 'action' => 'role.saved', 'after' => ['active' => true]]);
        RbacAudit::query()->create(['actor_id' => $actor->id, 'source' => 'web', 'scope_id' => $global->id, 'subject_type' => 'assignment', 'subject_id' => 1, 'action' => 'assignment.granted', 'after' => ['active' => true]]);
        $this->getJson($this->url($global, 'roles'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_type', 'role')->assertJsonPath('sources', ['roles', 'assignments', 'organization']);
        $this->getJson($this->url($global, 'assignments'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_type', 'assignment');
    }

    public function test_service_resolves_scope_identity_before_querying_history(): void
    {
        [$actor, $scope] = $this->administrator();
        $site = Site::factory()->create();
        $foreign = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        MasterDataAudit::query()->create(['actor_id' => $actor->id, 'scope_id' => $foreign->id, 'subject_type' => 'categories', 'subject_id' => 999, 'action' => 'created', 'after' => ['name_en' => 'Foreign secret']]);
        $forged = clone $scope;
        $forged->id = $foreign->id;

        $this->assertCount(0, app(AdministrationHistory::class)->query($actor, $forged, 'master-data')->get());
    }
}
