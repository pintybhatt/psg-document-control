<?php

namespace Tests\Feature;

use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\NumberingSeries;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ConfigureDemoDocumentsTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function fixture(): User
    {
        $this->definitions();
        $this->app['env'] = 'local';
        $actor = User::factory()->create(['email' => 'admin@dcms.test']);
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::where('code', 'SECURITY_ADMINISTRATOR')->value('id'), 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->artisan('dev:setup-phase3-demo', ['--operator' => 'Test operator'])->assertSuccessful();

        return $actor;
    }

    private function configure(): void
    {
        $this->artisan('dev:configure-demo-documents', ['--operator' => 'Test operator', '--no-interaction' => true])->assertSuccessful();
    }

    public function test_setup_is_visible_and_all_three_registers_create_unnumbered_drafts(): void
    {
        $actor = $this->fixture();
        $this->configure();
        $this->app['env'] = 'testing';
        $this->signIn($actor);
        $scope = AuthorizationScope::where('scope_key', 'site:1')->sole();
        $this->getJson($this->scopedUrl('document-master-data/categories', $scope))->assertOk()->assertJsonPath('meta.total', 7);
        $this->getJson($this->scopedUrl('document-master-data/types', $scope))->assertOk()->assertJsonPath('meta.total', 53);
        $this->getJson($this->scopedUrl('numbering-series', $scope))->assertOk()->assertJsonCount(3, 'data');
        $unit = ['company_id' => $scope->company_id, 'site_id' => $scope->site_id];
        $letter = DocumentType::where('code', 'ADM-LET')->sole();
        foreach (['incoming', 'outgoing'] as $key) {
            $this->getJson('/api/v1/'.$key.'-correspondence/lookups?'.http_build_query($unit))->assertOk()->assertJsonFragment(['id' => $letter->id, 'name_en' => 'Letter'])->assertJsonCount(32, 'data');
        }
        $this->postJson('/api/v1/incoming-correspondence', $unit + ['type_id' => $letter->id, 'classification' => 'normal', 'subject' => 'Synthetic incoming draft', 'sender_organization' => 'Example', 'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'channel' => 'email', 'priority' => 'normal'])->assertCreated()->assertJsonPath('data.internal_number', null);
        $this->postJson('/api/v1/outgoing-correspondence', $unit + ['type_id' => $letter->id, 'classification' => 'normal', 'subject' => 'Synthetic outgoing draft', 'signatory_name' => 'Example', 'document_date' => '2026-10-26', 'priority' => 'normal', 'recipients' => [['kind' => 'primary', 'organization' => 'Example']]])->assertCreated()->assertJsonPath('data.internal_number', null)->assertJsonPath('data.official_number', null);
        $sop = DocumentType::where('code', 'MIN-SOP')->sole();
        $this->getJson('/api/v1/controlled-documents/lookups?'.http_build_query($unit))->assertOk()->assertJsonFragment(['id' => $sop->id, 'name_en' => 'SOP'])->assertJsonCount(32, 'data');
        $this->postJson('/api/v1/controlled-documents', $unit + ['type_id' => $sop->id, 'classification' => 'normal', 'document_code' => 'DEMO/MIN/SOP/001', 'title_en' => 'Synthetic SOP draft', 'owner_id' => $actor->id, 'custodian_id' => $actor->id, 'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial demo', 'change_description' => 'Initial demo'])->assertCreated()->assertJsonPath('data.internal_number', null);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
        $this->assertSame(0, NumberingSeries::where('purpose', 'official_outgoing')->count());
    }

    public function test_second_run_preserves_records_timestamps_and_history(): void
    {
        $this->fixture();
        $this->configure();
        $before = [];
        foreach (['document_categories', 'document_types', 'register_configurations', 'document_type_register', 'numbering_series', 'numbering_configurations', 'numbering_namespaces', 'master_data_audits', 'roles', 'permission_role', 'user_role_assignments', 'organization_memberships'] as $table) {
            $before[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
        }
        $this->travel(1)->hours();
        $this->configure();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all(), $table);
        }
    }

    public function test_conflicting_category_rolls_back_the_entire_setup(): void
    {
        $this->fixture();
        DocumentCategory::factory()->create(['scope_id' => AuthorizationScope::where('scope_key', 'site:1')->value('id'), 'code' => 'ADM', 'name_en' => 'Conflicting category']);
        $this->artisan('dev:configure-demo-documents', ['--operator' => 'Test operator'])->assertFailed();
        $this->assertDatabaseCount('document_categories', 1);
        $this->assertDatabaseCount('register_configurations', 0);
        $this->assertDatabaseCount('numbering_series', 0);
        $this->assertDatabaseCount('master_data_audits', 0);
    }

    #[TestWith(['production'])]
    #[TestWith(['testing'])]
    public function test_nonlocal_environments_are_refused_without_writes(string $environment): void
    {
        $this->fixture();
        $this->app['env'] = $environment;
        $this->artisan('dev:configure-demo-documents', ['--operator' => 'Test operator'])->assertFailed();
        $this->assertDatabaseCount('document_categories', 0);
    }

    public function test_missing_demo_is_not_created(): void
    {
        $this->definitions();
        $this->app['env'] = 'local';
        $this->artisan('dev:configure-demo-documents', ['--operator' => 'Test operator'])->assertFailed();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('document_categories', 0);
    }
}
