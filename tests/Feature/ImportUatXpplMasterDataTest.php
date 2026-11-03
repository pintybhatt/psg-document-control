<?php

namespace Tests\Feature;

use App\Authorization\ImportXpplMasterData;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\MasterDataAudit;
use App\Models\RegisterConfiguration;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ImportUatXpplMasterDataTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/dcms-uat-import-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/seeders', 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function administrator(): User
    {
        $this->freezeTime();
        $this->definitions();
        $actor = User::factory()->create();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id, 'active' => true]);
        copy(base_path('database/seeders/xppl-master-data.json'), $this->directory.'/seeders/xppl-master-data.json');
        $this->app->useDatabasePath($this->directory);
        if (DB::getDriverName() === 'sqlite') {
            $identity = ['host' => 'memory', 'port' => '0', 'database' => ':memory:', 'server_uuid' => 'sqlite-test-only', 'account' => 'test-only'];
        } else {
            $server = DB::selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, CURRENT_USER() AS account');
            $identity = ['host' => DB::connection()->getConfig('host'), 'port' => (string) DB::connection()->getConfig('port'), 'database' => $server->db, 'server_uuid' => $server->uuid, 'account' => $server->account];
        }
        config(['xppl_uat_import' => ['enabled' => true] + $identity]);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode(file_get_contents(database_path('seeders/xppl-master-data.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $data */
    private function writeSource(array $data): void
    {
        file_put_contents(database_path('seeders/xppl-master-data.json'), json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function applyOptions(User $actor): array
    {
        $result = Artisan::call('uat:import-xppl-master-data', ['--no-interaction' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $result, $output);
        preg_match('/^Target JSON: (.+)$/m', $output, $target);
        preg_match('/^Plan SHA-256: ([a-f0-9]{64})$/m', $output, $hash);
        preg_match('/^Administrator confirmation: (.+)$/m', $output, $confirmation);
        $backup = $this->directory.'/backup.sql';
        file_put_contents($backup, '-- TEST-ONLY backup verification fixture; not a real UAT backup.');
        chmod($backup, 0600);
        $manifest = ['target' => json_decode($target[1], true, flags: JSON_THROW_ON_ERROR), 'plan_sha256' => $hash[1], 'backup_sha256' => hash_file('sha256', $backup),
            'created_at' => now()->subMinute()->toISOString(), 'verified_at' => now()->toISOString(), 'restore_verified' => true, 'verified_by' => $actor->id, 'verification_reference' => 'Synthetic restore verification for automated test'];
        file_put_contents($this->directory.'/backup.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        chmod($this->directory.'/backup.json', 0600);

        return ['--apply' => true, '--approve' => $hash[1], '--approve-provisional' => hash_file('sha256', database_path('seeders/xppl-master-data.json')),
            '--approval-reference' => 'TEST-PO-APPROVAL', '--actor' => $actor->id, '--backup' => $backup, '--backup-manifest' => $this->directory.'/backup.json', '--confirm' => $confirmation[1], '--no-interaction' => true];
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            $rows = DB::table($table)->get()->map(fn (\stdClass $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }

    public function test_preview_is_read_only_and_reports_all_provisional_values(): void
    {
        $this->administrator();
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data')->expectsOutput('departments: create=19, skip=0, conflict=0')
            ->expectsOutput('categories: create=39, skip=0, conflict=0')->expectsOutput('types: create=19, skip=0, conflict=0')
            ->expectsOutputToContain('NOT UAT-READY')->expectsOutputToContain('[DEV-UNTRANSLATED]')->expectsOutputToContain('Temporary application code')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->directory.'/backup.sql');
    }

    public function test_empty_business_target_import_is_idempotent_with_target_ids_and_audits(): void
    {
        $actor = $this->administrator();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $company = Company::query()->where('code', 'XPPL')->sole();
        $site = $company->sites()->where('code', 'XPPL')->sole();
        $this->assertDatabaseCount('departments', 19);
        $this->assertDatabaseCount('document_categories', 39);
        $this->assertDatabaseCount('document_types', 19);
        $this->assertDatabaseCount('register_configurations', 3);
        $scope = AuthorizationScope::context('site', $company->id, $site->id);
        $type = DocumentType::query()->where('scope_id', $scope->id)->where('code', 'SOP')->sole();
        $this->assertSame('CAT03', $type->category->code);
        $this->assertSame(['controlled'], $type->registers->map(fn (RegisterConfiguration $row): string => $row->register_key->value)->all());
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->expectsOutputToContain('IMPORTED: created=0;')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_nonmatching_dev_ids_and_existing_uat_documents_users_and_numbering_are_preserved(): void
    {
        $actor = $this->administrator();
        Company::factory()->create(['id' => 21]);
        Site::factory()->create(['id' => 41]);
        [$document] = $this->fixture();
        $scope = $document->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $document->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'KEEP/IN', 'padding' => 6, 'active' => true]);
        app(ManageIncomingCorrespondence::class)->register($actor, $scope, (string) $document->id);
        $this->postJson($this->url($document), ['file' => $this->pdf(), 'reason' => 'Existing UAT attachment'])->assertCreated();
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $after = $this->snapshot();
        foreach (['users', 'roles', 'permissions', 'user_role_assignments', 'organization_memberships', 'incoming_correspondences', 'document_attachments', 'attachment_versions', 'numbering_series', 'numbering_configurations', 'numbering_counters', 'issued_document_numbers', 'document_security_audits', 'storage_events'] as $table) {
            $this->assertSame($before[$table], $after[$table], $table.' changed');
        }
        $company = Company::query()->where('code', 'XPPL')->sole();
        $site = $company->sites()->where('code', 'XPPL')->sole();
        $this->assertNotSame(2, $company->id);
        $this->assertNotSame(2, $site->id);
        $this->assertSame([$company->id], Department::query()->where('site_id', $site->id)->distinct()->pluck('company_id')->all());
    }

    public function test_matching_existing_xppl_records_are_skipped_without_updates(): void
    {
        $actor = $this->administrator();
        $data = $this->data();
        $company = Company::query()->create($data['company']);
        $site = Site::query()->create($data['site'] + ['company_id' => $company->id]);
        $department = Department::query()->create($data['departments'][0] + ['company_id' => $company->id, 'site_id' => $site->id]);
        $before = [$company->fresh()->getAttributes(), $site->fresh()->getAttributes(), $department->fresh()->getAttributes()];
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $this->assertSame($before, [$company->fresh()->getAttributes(), $site->fresh()->getAttributes(), $department->fresh()->getAttributes()]);
    }

    #[TestWith(['name_en', 'Different company'])]
    #[TestWith(['name_lo', 'ຊື່ອື່ນ'])]
    #[TestWith(['active', false])]
    #[TestWith(['code', 'xppl'])]
    #[TestWith(['code', 'OTHER'])]
    public function test_existing_conflicting_codes_names_or_status_block_the_entire_import(string $field, mixed $value): void
    {
        $this->administrator();
        Company::query()->create(array_replace($this->data()['company'], [$field => $value]));
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Conflicts detected')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['duplicate'])]
    #[TestWith(['category'])]
    #[TestWith(['register'])]
    #[TestWith(['unknown'])]
    #[TestWith(['mapping_duplicate'])]
    #[TestWith(['inactive_parent'])]
    public function test_invalid_source_duplicates_missing_relationships_and_nonmaster_data_are_rejected(string $case): void
    {
        $this->administrator();
        $data = $this->data();
        match ($case) {
            'duplicate' => $data['departments'][] = $data['departments'][0],
            'category' => $data['types'][0]['category_code'] = 'MISSING',
            'register' => $data['registers'] = [$data['registers'][0]],
            'unknown' => $data['users'] = [['email' => 'never-import@example.test']],
            'mapping_duplicate' => $data['types'][0]['register_keys'] = ['controlled', 'controlled'],
            'inactive_parent' => $data['site']['active'] = false,
        };
        $this->writeSource($data);
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_audit_failure_rolls_back_every_insert_including_organization_and_scope(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $before = $this->snapshot();
        $event = 'eloquent.creating: '.MasterDataAudit::class;
        app('events')->listen($event, fn (MasterDataAudit $audit): ?bool => $audit->subject_type === 'types' ? false : null);
        try {
            $this->artisan('uat:import-xppl-master-data', $options)->assertFailed();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['local'])]
    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_wrong_environment_is_rejected_even_with_import_enabled(string $environment): void
    {
        $this->administrator();
        $this->app['env'] = $environment;
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Requires APP_ENV=uat')->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['host'])]
    #[TestWith(['port'])]
    #[TestWith(['database'])]
    #[TestWith(['server_uuid'])]
    #[TestWith(['account'])]
    public function test_wrong_target_identity_is_rejected(string $field): void
    {
        $this->administrator();
        config(['xppl_uat_import.'.$field => 'wrong-target']);
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Target '.$field.' differs')->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['--approve'])]
    #[TestWith(['--approve-provisional'])]
    #[TestWith(['--approval-reference'])]
    #[TestWith(['--confirm'])]
    #[TestWith(['--backup'])]
    #[TestWith(['--backup-manifest'])]
    public function test_missing_explicit_approval_confirmation_or_backup_prevents_apply(string $option): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $options[$option] = '';
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data', $options)->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_inactive_or_unprivileged_actor_cannot_import(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $other = User::factory()->create();
        $options['--actor'] = $other->id;
        $this->artisan('uat:import-xppl-master-data', $options)->assertFailed();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $other->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id, 'active' => true]);
        $actor->deactivate();
        $options['--actor'] = $actor->id;
        $this->artisan('uat:import-xppl-master-data', $options)->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_backup_corruption_or_unverified_restore_attestation_prevents_import(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        file_put_contents($options['--backup'], 'corrupt artifact');
        $this->artisan('uat:import-xppl-master-data', $options)->expectsOutputToContain('Backup integrity')->assertFailed();
        $options = $this->applyOptions($actor);
        $manifest = json_decode(file_get_contents($options['--backup-manifest']), true);
        $manifest['restore_verified'] = false;
        file_put_contents($options['--backup-manifest'], json_encode($manifest));
        $this->artisan('uat:import-xppl-master-data', $options)->expectsOutputToContain('Backup integrity')->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_disabled_import_and_uat_sqlite_are_refused(): void
    {
        $this->administrator();
        config(['xppl_uat_import.enabled' => false]);
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('UAT import is disabled')->assertFailed();
        if (DB::getDriverName() === 'sqlite') {
            config(['xppl_uat_import.enabled' => true]);
            $this->app['env'] = 'uat';
            $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Requires MySQL 8.4')->assertFailed();
        }
        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['stale'])]
    #[TestWith(['future'])]
    #[TestWith(['foreign_target'])]
    #[TestWith(['wrong_actor'])]
    #[TestWith(['public_permissions'])]
    public function test_backup_freshness_identity_and_private_file_checks_are_enforced(string $case): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $manifest = json_decode(file_get_contents($options['--backup-manifest']), true);
        match ($case) {
            'stale' => $manifest['created_at'] = now()->subDays(2)->toISOString(),
            'future' => $manifest['verified_at'] = now()->addHour()->toISOString(),
            'foreign_target' => $manifest['target']['database'] = 'wrong-database',
            'wrong_actor' => $manifest['verified_by'] = $actor->id + 1,
            'public_permissions' => chmod($options['--backup'], 0644),
        };
        file_put_contents($options['--backup-manifest'], json_encode($manifest));
        $this->artisan('uat:import-xppl-master-data', $options)->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_extra_register_mappings_are_conflicts_and_are_never_detached(): void
    {
        $actor = $this->administrator();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $type = DocumentType::query()->where('code', 'SOP')->sole();
        $register = RegisterConfiguration::query()->where('scope_id', $type->scope_id)->where('register_key', 'incoming')->sole();
        $type->registers()->attach($register->id, ['scope_id' => $type->scope_id]);
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('extra register mappings differ')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_approved_plan_cannot_be_reused_after_target_master_data_changes(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        Company::query()->create($this->data()['company']);
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data', $options)->expectsOutputToContain('exact reviewed plan hash')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reviewed_corrected_source_needs_plan_approval_but_no_provisional_override(): void
    {
        $actor = $this->administrator();
        $data = $this->data();
        $data['status'] = 'REVIEWED';
        $data['proposals_requiring_approval'] = [];
        foreach (['company', 'site'] as $kind) {
            $data[$kind]['name_lo'] = 'ທົດສອບ';
        }
        foreach (['departments', 'categories', 'types'] as $kind) {
            foreach ($data[$kind] as &$row) {
                $row['name_lo'] = 'ທົດສອບ '.$row['code'];
                if ($kind === 'categories') {
                    $row['code'] = 'TEST_'.$row['code'];
                } elseif ($kind === 'types') {
                    $row['category_code'] = 'TEST_'.$row['category_code'];
                }
            }
            unset($row);
        }
        $this->writeSource($data);
        $this->assertSame([], app(ImportXpplMasterData::class)->source()['issues']);
        $options = $this->applyOptions($actor);
        unset($options['--approve-provisional']);
        $this->artisan('uat:import-xppl-master-data', $options)->assertSuccessful();
        $this->assertDatabaseHas('document_categories', ['code' => 'TEST_CAT03']);
    }

    public function test_changed_category_relationship_is_a_conflict_without_history_changes(): void
    {
        $actor = $this->administrator();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $data = $this->data();
        $data['types'][0]['category_code'] = 'CAT04';
        $this->writeSource($data);
        $before = $this->snapshot();
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Category or extra register mappings differ')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_source_corrections_require_new_approval_and_never_rewrite_existing_history(): void
    {
        $actor = $this->administrator();
        $oldOptions = $this->applyOptions($actor);
        $data = $this->data();
        $data['types'][0]['name_lo'] = 'ນະໂຍບາຍ';
        $this->writeSource($data);
        $this->artisan('uat:import-xppl-master-data', $oldOptions)->assertFailed();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $before = $this->snapshot();
        $data['types'][0]['name_lo'] = 'ຊື່ແກ້ໄຂ';
        $this->writeSource($data);
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('Conflicts detected')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_mappings_are_added_without_changing_existing_type_metadata(): void
    {
        $actor = $this->administrator();
        $this->artisan('uat:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $type = DocumentType::query()->where('code', 'SOP')->sole();
        $before = $type->getAttributes();
        DB::table('document_type_register')->where('type_id', $type->id)->delete();
        $result = Artisan::call('uat:import-xppl-master-data', $this->applyOptions($actor));
        $this->assertSame(0, $result, Artisan::output());
        $this->assertSame($before, $type->fresh()->getAttributes());
        $this->assertCount(1, $type->fresh()->registers);
        $this->assertDatabaseHas('master_data_audits', ['subject_id' => $type->id, 'action' => 'updated']);
    }
}
