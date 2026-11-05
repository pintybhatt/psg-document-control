<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\CorrespondenceContexts;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\ConfidentialityClearance;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array{User, Site, AuthorizationScope} */
    private function workspace(): array
    {
        $this->definitions();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        $administrator = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $administrator->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id, 'active' => true]);
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $this->signIn($administrator);

        return [$administrator, $site, $scope];
    }

    private function reader(Site $site): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($user)->atSite($site)->create();
        $this->grant($user, AuthorizationScope::context('site', $site->company_id, $site->id), ['documents.metadata.view']);
        $this->signIn($user);

        return $user;
    }

    public function test_context_permissions_match_fresh_role_decisions_and_exact_memberships(): void
    {
        [, $site, $scope] = $this->workspace();
        $reader = $this->reader($site);
        $department = Department::factory()->for($site)->create();
        Department::factory()->for($site)->create();
        OrganizationMembership::factory()->for($reader)->inDepartment($department)->create();
        $this->grant($reader, $scope, ['numbering.issue', 'reports.view', 'roles.view']);
        $contexts = app(CorrespondenceContexts::class);
        $roles = app(AccessControl::class);

        $result = $contexts->forUser($reader);
        $this->assertCount(2, $result);
        foreach ($result as $context) {
            $expected = $roles->permissions($reader, $scope, $context['department_id']);
            sort($expected);
            $actual = $context['permissions'];
            sort($actual);
            $this->assertSame($expected, $actual);
        }
        OrganizationMembership::query()->where('user_id', $reader->id)->where('membership_type', 'site')->sole()
            ->forceFill(['active' => false, 'revoked_by' => $reader->id, 'revoked_at' => now()])->save();
        $remaining = $contexts->forUser($reader);
        $this->assertCount(1, $remaining);
        $this->assertSame($department->id, $remaining[0]['department_id']);
        $this->assertNotContains('numbering.issue', $remaining[0]['permissions']);
        $this->assertNotContains('roles.view', $remaining[0]['permissions']);
        $department->update(['active' => false]);
        $this->assertSame([], $contexts->forUser($reader));
    }

    private function url(Site $site, string $path = 'dashboard'): string
    {
        return '/api/v1/'.$path.'?'.http_build_query(['company_id' => $site->company_id, 'site_id' => $site->id]);
    }

    private function draft(Site $site, string $kind, string $subject, array $extra = []): int
    {
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $kind)->first()
            ?? RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => $kind]);
        $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
        $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
        $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
        $payload = ['company_id' => $site->company_id, 'site_id' => $site->id, 'type_id' => $type->id, 'subject' => $subject, 'priority' => 'normal', 'classification' => 'normal'];
        $payload += $kind === 'incoming'
            ? ['sender_organization' => 'Sender', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email']
            : ['signatory_name' => 'Signatory', 'document_date' => '2026-10-26', 'recipients' => [['kind' => 'primary', 'organization' => 'Recipient']]];

        return $this->postJson('/api/v1/'.$kind.'-correspondence', array_replace($payload, $extra))->assertCreated()->json('data.id');
    }

    public function test_dashboard_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/dashboard?company_id=1&site_id=1')->assertUnauthorized();
    }

    public function test_authorized_empty_workspace_and_registers_return_empty_data_without_failure(): void
    {
        [, $site] = $this->workspace();
        $this->reader($site);

        $this->getJson($this->url($site))->assertOk()->assertJsonPath('totals.incoming', 0)->assertJsonPath('totals.outgoing', 0)->assertJsonPath('totals.due_soon', 0)->assertJsonPath('totals.overdue', 0)->assertJsonPath('totals.controlled', 0)->assertJsonPath('recent', [])->assertJsonPath('due', [])->assertJsonPath('quick_actions', [])->assertHeader('Cache-Control', 'no-store, private');
        foreach (['incoming', 'outgoing'] as $kind) {
            $this->getJson($this->url($site, $kind.'-correspondence'))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        }
    }

    public function test_forged_workspace_and_revoked_membership_cannot_read_dashboard(): void
    {
        [, $site] = $this->workspace();
        $user = $this->reader($site);
        $other = Site::factory()->create();

        $this->getJson($this->url($other))->assertForbidden();
        $this->getJson('/api/v1/dashboard?'.http_build_query(['company_id' => $other->company_id, 'site_id' => $site->id]))->assertForbidden();
        OrganizationMembership::query()->where('user_id', $user->id)->update(['active' => false]);
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->url($site))->assertForbidden();
    }

    public function test_dashboard_counts_and_recent_metadata_obey_confidentiality_and_exact_membership(): void
    {
        [, $site] = $this->workspace();
        $this->draft($site, 'incoming', 'Visible incoming');
        $this->draft($site, 'outgoing', 'Visible outgoing');
        $this->draft($site, 'incoming', 'Confidential incoming', ['classification' => 'confidential']);
        $this->draft($site, 'outgoing', 'Restricted outgoing', ['classification' => 'highly_confidential']);
        $department = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
        $this->draft($site, 'incoming', 'Other department', ['department_id' => $department->id]);
        $this->draft(Site::factory()->create(), 'outgoing', 'Other company');
        $reader = $this->reader($site);
        ConfidentialityClearance::factory()->create(['user_id' => $reader->id, 'company_id' => $site->company_id, 'site_id' => $site->id, 'department_id' => null, 'classification' => 'confidential']);
        ConfidentialityClearance::factory()->create(['user_id' => $reader->id, 'company_id' => $site->company_id, 'site_id' => $site->id, 'department_id' => null, 'classification' => 'highly_confidential']);

        $response = $this->getJson($this->url($site))->assertOk()->assertJsonPath('totals.incoming', 2)->assertJsonPath('totals.outgoing', 1)->assertJsonCount(3, 'recent')->assertJsonPath('quick_actions', []);
        $response->assertJsonMissing(['subject' => 'Restricted outgoing'])->assertJsonMissing(['subject' => 'Other department'])->assertJsonMissing(['subject' => 'Other company']);
        ConfidentialityClearance::query()->where('user_id', $reader->id)->update(['active' => false]);
        $this->getJson($this->url($site))->assertOk()->assertJsonPath('totals.incoming', 1)->assertJsonCount(2, 'recent')->assertJsonMissing(['subject' => 'Confidential incoming']);
    }

    public function test_system_administrator_sees_authorized_department_and_confidential_data_without_membership(): void
    {
        [, $site] = $this->workspace();
        $department = Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id]);
        $id = $this->draft($site, 'incoming', 'Administrator document', ['classification' => 'highly_confidential', 'department_id' => $department->id]);

        $this->getJson($this->url($site))->assertOk()->assertJsonPath('totals.incoming', 1)->assertJsonPath('recent.0.id', $id)->assertJsonCount(2, 'quick_actions');
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonCount(2, 'data');
        $site->company->update(['active' => false]);
        $this->getJson('/api/v1/incoming-correspondence/contexts')->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->url($site))->assertForbidden();
    }

    public function test_due_counts_include_today_and_seven_days_but_exclude_closed_and_inaccessible_records(): void
    {
        [$administrator, $site, $scope] = $this->workspace();
        foreach (['2026-10-25', '2026-10-26', '2026-11-02', '2026-11-03', '2026-10-20'] as $index => $date) {
            $id = $this->draft($site, 'incoming', 'Due '.$index, ['classification' => $index === 4 ? 'confidential' : 'normal']);
            if ($index === 0) {
                app(ManageNumbering::class)->save($administrator, $scope, ['register_id' => RegisterConfiguration::query()->sole()->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'DASH/IN', 'padding' => 6, 'active' => true]);
            }
            $this->postJson('/api/v1/incoming-correspondence/'.$id.'/register', ['company_id' => $site->company_id, 'site_id' => $site->id])->assertOk();
            $this->postJson('/api/v1/incoming-correspondence/'.$id.'/operations', ['company_id' => $site->company_id, 'site_id' => $site->id, 'action' => 'update', 'revision' => 0, 'due_date' => $date, 'response_required' => false, 'response_completed' => false])->assertOk();
            if ($index === 1) {
                $this->postJson('/api/v1/incoming-correspondence/'.$id.'/operations', ['company_id' => $site->company_id, 'site_id' => $site->id, 'action' => 'close', 'revision' => 1])->assertOk();
            }
        }
        $this->reader($site);

        $this->getJson($this->url($site))->assertOk()->assertJsonPath('totals.incoming', 4)->assertJsonPath('totals.overdue', 1)->assertJsonPath('totals.due_soon', 1)->assertJsonCount(2, 'due')->assertJsonPath('due.0.due_date', '2026-10-25')->assertJsonPath('due.1.due_date', '2026-11-02');
    }
}
