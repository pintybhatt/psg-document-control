<?php

namespace Tests\Feature;

use App\Authorization\ManageApprovalWorkflows;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Models\ApprovalWorkflowConfiguration;
use App\Models\AuthorizationScope;
use App\Models\ControlledRevisionApproval;
use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ApprovalWorkflowConfigurationTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, AuthorizationScope, Role} */
    private function administrator(string $type = 'site'): array
    {
        $this->definitions();
        config(['app.debug' => false]);
        $this->freezeTime();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context($type, $site->company_id, $type === 'site' ? $site->id : null)->persist();
        $actor = User::factory()->create();
        $membership = OrganizationMembership::factory()->for($actor);
        if ($type === 'site') {
            $membership->atSite($site)->create();
        } else {
            $membership->create(['company_id' => $site->company_id]);
        }
        $role = $this->grant($actor, $scope, ['master-data.view', 'master-data.manage', 'roles.view']);
        $this->signIn($actor);

        return [$actor, $scope, $role];
    }

    private function url(AuthorizationScope $scope, string $suffix = ''): string
    {
        return $this->scopedUrl('approval-workflows'.$suffix, $scope);
    }

    /** @param list<int> $roles @return array<string, mixed> */
    private function payload(array $roles = [], bool $enabled = false, int $version = 0): array
    {
        return ['expected_version' => $version, 'enabled' => $enabled, 'sequence' => 'sequential', 'role_ids' => $roles, 'change_reason' => 'Synthetic configuration review'];
    }

    public function test_all_registers_are_disabled_without_writing_configuration_or_approvals(): void
    {
        [, $scope] = $this->administrator();

        $this->getJson($this->url($scope))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('enforcement_enabled', false)
            ->assertJsonPath('data.0.enabled', false)->assertJsonPath('data.1.enabled', false)->assertJsonPath('data.2.enabled', false)->assertJsonPath('data.0.version', 0);
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
        $this->assertDatabaseCount('controlled_revision_approvals', 0);
    }

    #[TestWith(['controlled'])]
    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_configuration_saves_sequential_role_snapshots_and_preserves_each_audited_version(string $register): void
    {
        [$actor, $scope] = $this->administrator();
        $roles = Role::factory()->count(3)->create(['scope_id' => $scope->id]);

        $this->postJson($this->url($scope, '/'.$register), $this->payload($roles->modelKeys(), true))->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.enabled', true)->assertJsonPath('data.sequence', 'sequential')
            ->assertJsonPath('data.created_by', $actor->id)->assertJsonPath('data.levels.2.level', 3)->assertJsonPath('data.levels.0.role.id', $roles[0]->id)
            ->assertJsonPath('data.change_reason', 'Synthetic configuration review');
        $first = ApprovalWorkflowConfiguration::query()->sole()->getAttributes();
        $roles[0]->update(['name_en' => 'Renamed later']);
        $this->postJson($this->url($scope, '/'.$register), $this->payload([], false, 1))->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.enabled', false);
        $this->getJson($this->url($scope, '/'.$register.'/history'))->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.1.levels.0.role.name_en', 'Example Role')->assertJsonPath('data.0.levels', []);
        $this->assertSame($first, ApprovalWorkflowConfiguration::query()->oldest('id')->first()->getAttributes());
        $this->getJson($this->url($scope))->assertOk()->assertJsonFragment(['register_key' => $register, 'version' => 2, 'enabled' => false]);
        $this->assertDatabaseCount('approval_workflow_configurations', 2);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    #[TestWith(['company'])]
    #[TestWith(['site'])]
    public function test_company_and_site_settings_are_independent_and_parent_roles_can_be_selected(string $type): void
    {
        [$actor, $scope] = $this->administrator($type);
        $company = AuthorizationScope::context('company', $scope->company_id)->persist();
        $role = Role::factory()->create(['scope_id' => $company->id]);

        $this->postJson($this->url($scope, '/controlled'), $this->payload([$role->id], true))->assertCreated()->assertJsonPath('data.scope_id', $scope->id);
        $other = $type === 'site' ? $company : AuthorizationScope::context('site', $scope->company_id, Site::query()->where('company_id', $scope->company_id)->sole()->id)->persist();
        if ($type === 'site') {
            OrganizationMembership::factory()->for($actor)->create(['company_id' => $scope->company_id]);
            $this->grant($actor, $company, ['master-data.view']);
        } else {
            OrganizationMembership::factory()->for($actor)->atSite($other->site)->create();
        }
        $this->getJson($this->url($other))->assertOk()->assertJsonPath('data.0.enabled', false)->assertJsonPath('data.0.version', 0);
        $this->getJson($this->url($other, '/controlled/history'))->assertOk()->assertJsonPath('total', 0);
    }

    #[TestWith(['expected_version', -1])]
    #[TestWith(['enabled', 'yes'])]
    #[TestWith(['sequence', 'parallel'])]
    #[TestWith(['role_ids', [1, 2, 3, 4]])]
    #[TestWith(['role_ids', [1, 1]])]
    #[TestWith(['role_ids', ['1' => 1]])]
    #[TestWith(['change_reason', ''])]
    #[TestWith(['created_by', 999])]
    #[TestWith(['version', 99])]
    public function test_invalid_and_server_controlled_settings_are_rejected_without_a_history_entry(string $field, mixed $value): void
    {
        [, $scope] = $this->administrator();

        $this->postJson($this->url($scope, '/controlled'), array_replace($this->payload(), [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    public function test_enabled_configuration_requires_at_least_one_role(): void
    {
        [, $scope] = $this->administrator();

        $this->postJson($this->url($scope, '/controlled'), $this->payload([], true))->assertUnprocessable()->assertJsonPath('errors.role_ids.0', 'workflowValidation');
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    #[TestWith(['inactive'])]
    #[TestWith(['foreign_site'])]
    #[TestWith(['foreign_company'])]
    #[TestWith(['privileged'])]
    #[TestWith(['inactive_permission'])]
    #[TestWith(['missing'])]
    public function test_unauthorized_roles_are_hidden_from_choices_and_cannot_be_saved(string $boundary): void
    {
        [, $scope] = $this->administrator();
        $foreign = Site::factory()->create($boundary === 'foreign_site' ? ['company_id' => $scope->company_id] : []);
        $roleScope = in_array($boundary, ['foreign_site', 'foreign_company'], true) ? AuthorizationScope::context('site', $foreign->company_id, $foreign->id)->persist() : $scope;
        $role = Role::factory()->create(['scope_id' => $roleScope->id, 'active' => $boundary !== 'inactive']);
        if ($boundary === 'privileged') {
            $role->permissions()->attach(Permission::query()->where('key', 'assignments.manage')->sole());
        } elseif ($boundary === 'inactive_permission') {
            $permission = Permission::query()->where('key', 'organization.access')->sole();
            $permission->update(['active' => false]);
            $role->permissions()->attach($permission);
        }
        $roleId = $boundary === 'missing' ? 999999 : $role->id;

        $this->getJson($this->url($scope, '/roles'))->assertOk()->assertJsonMissing(['id' => $roleId]);
        $response = $this->postJson($this->url($scope, '/controlled'), $this->payload([$roleId], true))->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
        $this->assertSame(['workflowInvalidRole'], $response->json('errors')['role_ids.0']);
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    #[TestWith(['permission'])]
    #[TestWith(['roles'])]
    #[TestWith(['membership'])]
    #[TestWith(['company_inactive'])]
    public function test_current_authority_is_required_for_management(string $boundary): void
    {
        [$actor, $scope, $role] = $this->administrator();
        if ($boundary === 'permission' || $boundary === 'roles') {
            $role->permissions()->detach(Permission::query()->where('key', $boundary === 'roles' ? 'roles.view' : 'master-data.manage')->value('id'));
        } elseif ($boundary === 'membership') {
            $actor->memberships()->sole()->update(['active' => false]);
        } else {
            $scope->company->update(['active' => false]);
        }

        $this->postJson($this->url($scope, '/incoming'), $this->payload())->assertForbidden();
        $this->getJson($this->url($scope, '/roles'))->assertForbidden();
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    public function test_guests_and_foreign_scopes_cannot_read_or_rewrite_history(): void
    {
        [, $scope] = $this->administrator();
        $this->postJson($this->url($scope, '/incoming'), $this->payload())->assertCreated();
        [, $other] = $this->administrator();

        $this->getJson($this->url($scope))->assertForbidden();
        $this->getJson($this->url($scope, '/incoming/history'))->assertForbidden();
        $this->postJson($this->url($scope, '/incoming'), $this->payload([], false, 1))->assertForbidden();
        $this->getJson($this->url($other))->assertOk()->assertJsonPath('data.1.version', 0);
        auth()->logout();
        $this->getJson($this->url($other))->assertUnauthorized();
        $this->postJson($this->url($other, '/incoming'), $this->payload())->assertUnauthorized();
    }

    public function test_read_only_administrators_can_review_history_but_not_save_or_select_roles(): void
    {
        [, $scope, $role] = $this->administrator();
        $role->permissions()->detach(Permission::query()->where('key', 'master-data.manage')->value('id'));

        $this->getJson($this->url($scope))->assertOk();
        $this->getJson($this->url($scope, '/controlled/history'))->assertOk();
        $this->getJson($this->url($scope, '/roles'))->assertForbidden();
        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertForbidden();
    }

    public function test_global_and_mismatched_scope_shapes_and_unknown_registers_are_rejected(): void
    {
        [, $scope] = $this->administrator();
        $this->getJson($this->url(AuthorizationScope::context('global')))->assertForbidden();
        $other = Site::factory()->create();
        $this->postJson('/api/v1/approval-workflows/controlled', $this->payload() + ['scope_type' => 'site', 'company_id' => $scope->company_id, 'site_id' => $other->id])->assertForbidden();
        $this->postJson($this->url($scope, '/unknown'), $this->payload())->assertNotFound();
        $this->getJson($this->url($scope, '/unknown/history'))->assertNotFound();
        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    public function test_stale_writers_cannot_overwrite_a_configuration_including_the_initial_save(): void
    {
        [, $scope] = $this->administrator();
        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertCreated();

        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertUnprocessable()->assertJsonPath('errors.expected_version.0', 'workflowStale');
        $this->postJson($this->url($scope, '/controlled'), $this->payload([], false, 99))->assertUnprocessable();
        $this->assertDatabaseCount('approval_workflow_configurations', 1);
    }

    #[TestWith(['persistence'])]
    #[TestWith(['outer'])]
    public function test_failed_or_rolled_back_saves_leave_no_configuration_or_history(string $boundary): void
    {
        [$actor, $scope] = $this->administrator();
        if ($boundary === 'outer') {
            DB::beginTransaction();
            app(ManageApprovalWorkflows::class)->save($actor, $scope, 'controlled', $this->payload());
            DB::rollBack();
        } else {
            $event = 'eloquent.creating: '.ApprovalWorkflowConfiguration::class;
            Event::listen($event, fn (): bool => false);
            try {
                $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertInternalServerError();
            } finally {
                Event::forget($event);
            }
        }

        $this->assertDatabaseCount('approval_workflow_configurations', 0);
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['replace'])]
    #[TestWith(['skip_version'])]
    public function test_database_protects_configuration_history_and_version_order(string $operation): void
    {
        [, $scope] = $this->administrator();
        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertCreated();

        $this->expectException(QueryException::class);
        if ($operation === 'update') {
            DB::table('approval_workflow_configurations')->update(['enabled' => true]);
        } elseif ($operation === 'delete') {
            DB::table('approval_workflow_configurations')->delete();
        } elseif ($operation === 'skip_version') {
            $row = (array) DB::table('approval_workflow_configurations')->sole();
            unset($row['id']);
            DB::table('approval_workflow_configurations')->insert(array_replace($row, ['version' => 3]));
        } else {
            DB::statement((DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO approval_workflow_configurations SELECT * FROM approval_workflow_configurations');
        }
    }

    public function test_model_history_is_immutable_and_posts_require_csrf(): void
    {
        [, $scope] = $this->administrator();
        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertCreated();
        $this->patchJson($this->url($scope, '/controlled'), $this->payload())->assertStatus(405);
        $this->deleteJson($this->url($scope, '/controlled'))->assertStatus(405);
        $this->app['env'] = 'local';
        $this->postJson($this->url($scope, '/controlled'), $this->payload())->assertStatus(419);

        $this->expectException(ValidationException::class);
        ApprovalWorkflowConfiguration::query()->sole()->update(['enabled' => true]);
    }

    #[TestWith(['controlled'])]
    #[TestWith(['incoming'])]
    #[TestWith(['outgoing'])]
    public function test_enabled_configuration_does_not_gate_existing_registration_release_or_reinterpret_evidence(string $register): void
    {
        [$record, $actor, $role] = $this->fixture($register);
        $scope = $record->profile->documentScope();
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['master-data.view', 'master-data.manage', 'roles.view', 'incoming.register', 'outgoing.register'])->pluck('id'));
        $manager = app(match ($register) {
            'controlled' => ManageControlledDocuments::class, 'incoming' => ManageIncomingCorrespondence::class, 'outgoing' => ManageOutgoingCorrespondence::class,
        });
        if ($register === 'controlled') {
            $record = $manager->recordApproval($actor, $scope, (string) $record->id, (string) $record->revisions()->sole()->id, ['lock_version' => $record->lock_version, 'decision' => 'submitted']);
        }
        $before = $record->getAttributes();
        $evidence = ControlledRevisionApproval::query()->get()->map->getAttributes()->all();
        $approver = Role::factory()->create(['scope_id' => $scope->authorizationScope()->id]);

        app(ManageApprovalWorkflows::class)->save($actor, $scope->authorizationScope(), $register, $this->payload([$approver->id], true));
        app(ManageApprovalWorkflows::class)->save($actor, $scope->authorizationScope(), $register, $this->payload([], false, 1));
        app(ManageApprovalWorkflows::class)->save($actor, $scope->authorizationScope(), $register, $this->payload([$approver->id], true, 2));
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($evidence, ControlledRevisionApproval::query()->get()->map->getAttributes()->all());
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'COMPAT/'.strtoupper($register), 'padding' => 4, 'active' => true]);
        if ($register === 'outgoing') {
            app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $record->register_id, 'purpose' => 'official_outgoing', 'reset_policy' => 'annual', 'prefix' => 'COMPAT/OFFICIAL', 'padding' => 4, 'active' => true]);
        }
        $record = $register === 'controlled'
            ? $manager->register($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version])
            : $manager->register($actor, $scope, (string) $record->id);
        $this->assertTrue($record->isFormallyRegistered());
        $this->assertStringEndsWith('/0001', $record->internal_number);
        if ($register === 'controlled') {
            $revision = $record->revisions()->sole();
            $released = $manager->release($actor, $scope, (string) $record->id, ['lock_version' => $record->lock_version, 'revision_id' => $revision->id, 'reason' => 'Existing authorized release']);
            $this->assertSame('current', $released->status);
            $this->assertSame($evidence, ControlledRevisionApproval::query()->get()->map->getAttributes()->all());
        }
    }
}
