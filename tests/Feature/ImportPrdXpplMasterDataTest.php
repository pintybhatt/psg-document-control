<?php

namespace Tests\Feature;

use App\Authorization\ImportXpplMasterData;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Authorization\RbacMutation;
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
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ImportPrdXpplMasterDataTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', $this->app->environment());
        $this->directory = sys_get_temp_dir().'/dcms-prd-import-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/seeders', 0700, true);

        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped(
                'Production importer integration fixtures require isolated in-memory SQLite; MySQL target-guard behavior is tested separately.'
            );
        }

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
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
        $identity = ['host' => 'memory', 'port' => '0', 'database' => ':memory:', 'server_uuid' => 'sqlite-test-only', 'account' => 'test-only'];
        config(['xppl_prd_import' => ['enabled' => true] + $identity]);

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
        $result = Artisan::call('prd:import-xppl-master-data', ['--no-interaction' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $result, $output);
        preg_match('/^Target JSON: (.+)$/m', $output, $target);
        preg_match('/^Plan SHA-256: ([a-f0-9]{64})$/m', $output, $hash);
        preg_match('/^Administrator confirmation: (.+)$/m', $output, $confirmation);
        $backup = $this->directory.'/backup.sql';
        file_put_contents($backup, '-- TEST-ONLY backup verification fixture; not a real Production backup.');
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
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->artisan('prd:import-xppl-master-data')->expectsOutput('departments: create=19, skip=0, conflict=0')
            ->expectsOutput('categories: create=39, skip=0, conflict=0')->expectsOutput('types: create=19, skip=0, conflict=0')
            ->expectsOutputToContain('PRODUCTION BASELINE REQUIRES EXPLICIT PRODUCT OWNER APPROVAL')->expectsOutputToContain('[DEV-UNTRANSLATED]')->expectsOutputToContain('Temporary application code')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $writes);
        $this->assertFileDoesNotExist($this->directory.'/backup.sql');
    }

    public function test_empty_business_target_import_is_idempotent_with_target_ids_and_audits(): void
    {
        $actor = $this->administrator();
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
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
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->expectsOutputToContain('IMPORTED: created=0;')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_nonmatching_dev_ids_and_existing_production_documents_users_and_numbering_are_preserved(): void
    {
        $actor = $this->administrator();
        Company::factory()->create(['id' => 21]);
        Site::factory()->create(['id' => 41]);
        [$document] = $this->fixture();
        $scope = $document->profile->documentScope();
        app(ManageNumbering::class)->save($actor, $scope->authorizationScope(), ['register_id' => $document->register_id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'KEEP/IN', 'padding' => 6, 'active' => true]);
        app(ManageIncomingCorrespondence::class)->register($actor, $scope, (string) $document->id);
        $this->postJson($this->url($document), ['file' => $this->pdf(), 'reason' => 'Existing Production attachment'])->assertCreated();
        $before = $this->snapshot();
        $preservedRows = [];
        foreach (array_keys($before) as $table) {
            $preservedRows[$table] = DB::table($table)->get()->map(fn (\stdClass $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        }
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $after = $this->snapshot();
        foreach (['users', 'roles', 'permissions', 'user_role_assignments', 'organization_memberships', 'incoming_correspondences', 'document_attachments', 'attachment_versions', 'numbering_series', 'numbering_configurations', 'numbering_counters', 'issued_document_numbers', 'document_security_audits', 'storage_events'] as $table) {
            $this->assertSame($before[$table], $after[$table], $table.' changed');
        }
        foreach ($preservedRows as $table => $rows) {
            $currentRows = DB::table($table)->get()->map(fn (\stdClass $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            $this->assertSame([], array_values(array_diff($rows, $currentRows)), $table.' lost or modified existing records');
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
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
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
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Conflicts detected')->assertFailed();
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
        $this->artisan('prd:import-xppl-master-data')->assertFailed();
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
            $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['local'])]
    #[TestWith(['development'])]
    #[TestWith(['uat'])]
    #[TestWith(['staging'])]
    public function test_wrong_environment_is_rejected_even_with_import_enabled(string $environment): void
    {
        $this->administrator();
        $this->app['env'] = $environment;
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Requires APP_ENV=production')->assertFailed();
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
        config(['xppl_prd_import.'.$field => 'wrong-target']);
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Target '.$field.' differs')->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['--actor'])]
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
        $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_inactive_or_unprivileged_actor_cannot_import(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $other = User::factory()->create();
        $options['--actor'] = $other->id;
        $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole();
        UserRoleAssignment::query()->create(['user_id' => $other->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id, 'active' => true]);
        $actor->deactivate();
        $options['--actor'] = $actor->id;
        $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_backup_corruption_or_unverified_restore_attestation_prevents_import(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        file_put_contents($options['--backup'], 'corrupt artifact');
        $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain('Backup integrity')->assertFailed();
        $options = $this->applyOptions($actor);
        $manifest = json_decode(file_get_contents($options['--backup-manifest']), true);
        $manifest['restore_verified'] = false;
        file_put_contents($options['--backup-manifest'], json_encode($manifest));
        $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain('Backup integrity')->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_disabled_import_and_production_sqlite_are_refused(): void
    {
        $this->administrator();
        config(['xppl_prd_import.enabled' => false]);
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Production import is disabled')->assertFailed();
        if (DB::getDriverName() === 'sqlite') {
            config(['xppl_prd_import.enabled' => true]);
            $this->app['env'] = 'production';
            $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Requires MySQL 8.4')->assertFailed();
        }
        $this->assertDatabaseCount('companies', 0);
    }

    #[TestWith(['stale'])]
    #[TestWith(['future'])]
    #[TestWith(['future_backup'])]
    #[TestWith(['no_timezone'])]
    #[TestWith(['public_path'])]
    #[TestWith(['relative_manifest'])]
    #[TestWith(['foreign_target'])]
    #[TestWith(['wrong_actor'])]
    #[TestWith(['public_permissions'])]
    #[TestWith(['manifest_permissions'])]
    #[TestWith(['wrong_plan'])]
    #[TestWith(['missing_reference'])]
    #[TestWith(['incomplete_restore'])]
    #[TestWith(['relative_timestamp'])]
    #[TestWith(['missing_backup'])]
    #[TestWith(['missing_manifest'])]
    #[TestWith(['empty_backup'])]
    #[TestWith(['invalid_manifest'])]
    #[TestWith(['relative_path'])]
    #[TestWith(['symlink'])]
    public function test_backup_freshness_identity_and_private_file_checks_are_enforced(string $case): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $manifest = json_decode(file_get_contents($options['--backup-manifest']), true);
        match ($case) {
            'stale' => $manifest['created_at'] = now()->subDays(2)->toISOString(),
            'future' => $manifest['verified_at'] = now()->addHour()->toISOString(),
            'future_backup' => $manifest['created_at'] = now()->addHour()->toISOString(),
            'no_timezone' => $manifest['created_at'] = now()->subMinute()->format('Y-m-d\TH:i:s'),
            'public_path' => $this->app->usePublicPath($this->directory),
            'relative_manifest' => $options['--backup-manifest'] = 'backup.json',
            'foreign_target' => $manifest['target']['database'] = 'wrong-database',
            'wrong_actor' => $manifest['verified_by'] = $actor->id + 1,
            'public_permissions' => chmod($options['--backup'], 0644),
            'manifest_permissions' => chmod($options['--backup-manifest'], 0644),
            'wrong_plan' => $manifest['plan_sha256'] = str_repeat('0', 64),
            'missing_reference' => $manifest['verification_reference'] = ' ',
            'incomplete_restore' => $manifest['verified_at'] = now()->subMinutes(2)->toISOString(),
            'relative_timestamp' => $manifest['created_at'] = 'yesterday',
            'missing_backup' => unlink($options['--backup']),
            'missing_manifest' => $options['--backup-manifest'] .= '.missing',
            'empty_backup' => file_put_contents($options['--backup'], ''),
            'invalid_manifest' => $manifest = null,
            'relative_path' => $options['--backup'] = 'backup.sql',
            'symlink' => symlink($options['--backup'], $this->directory.'/link.sql'),
        };
        if (! in_array($case, ['missing_manifest', 'relative_manifest'], true)) {
            file_put_contents($options['--backup-manifest'], json_encode($manifest));
        }
        if ($case === 'symlink') {
            $options['--backup'] = $this->directory.'/link.sql';
        }
        $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_extra_register_mappings_are_conflicts_and_are_never_detached(): void
    {
        $actor = $this->administrator();
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $type = DocumentType::query()->where('code', 'SOP')->sole();
        $register = RegisterConfiguration::query()->where('scope_id', $type->scope_id)->where('register_key', 'incoming')->sole();
        $type->registers()->attach($register->id, ['scope_id' => $type->scope_id]);
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('extra register mappings differ')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_approved_plan_cannot_be_reused_after_target_master_data_changes(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        Company::query()->create($this->data()['company']);
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain('exact reviewed plan hash')->assertFailed();
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
        $this->artisan('prd:import-xppl-master-data', $options)->assertSuccessful();
        $this->assertDatabaseHas('document_categories', ['code' => 'TEST_CAT03']);
    }

    public function test_changed_category_relationship_is_a_conflict_without_history_changes(): void
    {
        $actor = $this->administrator();
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $data = $this->data();
        $data['types'][0]['category_code'] = 'CAT04';
        $this->writeSource($data);
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Category or extra register mappings differ')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_source_corrections_require_new_approval_and_never_rewrite_existing_history(): void
    {
        $actor = $this->administrator();
        $oldOptions = $this->applyOptions($actor);
        $data = $this->data();
        $data['types'][0]['name_lo'] = 'ນະໂຍບາຍ';
        $this->writeSource($data);
        $this->artisan('prd:import-xppl-master-data', $oldOptions)->assertFailed();
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $before = $this->snapshot();
        $data['types'][0]['name_lo'] = 'ຊື່ແກ້ໄຂ';
        $this->writeSource($data);
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Conflicts detected')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_mappings_are_added_without_changing_existing_type_metadata(): void
    {
        $actor = $this->administrator();
        $this->artisan('prd:import-xppl-master-data', $this->applyOptions($actor))->assertSuccessful();
        $type = DocumentType::query()->where('code', 'SOP')->sole();
        $before = $type->getAttributes();
        DB::table('document_type_register')->where('type_id', $type->id)->delete();
        $result = Artisan::call('prd:import-xppl-master-data', $this->applyOptions($actor));
        $this->assertSame(0, $result, Artisan::output());
        $this->assertSame($before, $type->fresh()->getAttributes());
        $this->assertCount(1, $type->fresh()->registers);
        $this->assertDatabaseHas('master_data_audits', ['subject_id' => $type->id, 'action' => 'updated']);
    }

    public function test_production_configuration_never_falls_back_to_uat(): void
    {
        $this->administrator();
        config(['xppl_uat_import' => config('xppl_prd_import'), 'xppl_prd_import.enabled' => false]);
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Production import is disabled')->assertFailed();
        config(['xppl_prd_import.enabled' => true, 'xppl_prd_import.host' => null]);
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Target host differs')->assertFailed();
        config(['xppl_uat_import.enabled' => false, 'xppl_prd_import.host' => 'memory']);
        $this->artisan('prd:import-xppl-master-data')->assertSuccessful();
        $this->artisan('uat:import-xppl-master-data')->expectsOutputToContain('UAT import is disabled')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['--approve'])]
    #[TestWith(['--approve-provisional'])]
    #[TestWith(['--confirm'])]
    public function test_incorrect_hash_or_confirmation_cannot_authorize_import(string $option): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $options[$option] = 'incorrect-approval';
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data', $options)->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['authority', 'Target or administrator authority changed'])]
    #[TestWith(['target', 'Target host differs'])]
    #[TestWith(['plan', 'Target master data changed since approval'])]
    #[TestWith(['source', 'Source changed since approval'])]
    #[TestWith(['backup', 'Backup integrity'])]
    public function test_changes_before_the_locked_recheck_refuse_import(string $case, string $message): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $before = $this->snapshot();
        $change = function () use ($case, $actor, $options): void {
            match ($case) {
                'authority' => DB::table('user_role_assignments')->where('user_id', $actor->id)->update(['active' => false]),
                'target' => config(['xppl_prd_import.host' => 'changed-target']),
                'plan' => Company::query()->create($this->data()['company']),
                'source' => $this->writeSource(array_replace($this->data(), ['status' => 'CHANGED'])),
                'backup' => file_put_contents($options['--backup'], 'changed backup'),
            };
        };
        $this->app->instance(RbacMutation::class, new class($change) extends RbacMutation
        {
            public function __construct(private Closure $change) {}

            public function run(Closure $operation): mixed
            {
                return parent::run(function () use ($operation): mixed {
                    ($this->change)();

                    return $operation();
                });
            }
        });
        $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain($message)->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failed_post_import_verification_rolls_back_all_inserts(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $before = $this->snapshot();
        $event = 'eloquent.created: '.MasterDataAudit::class;
        app('events')->listen($event, function (MasterDataAudit $audit): void {
            if ($audit->subject_type === 'types') {
                DB::table('document_types')->where('id', $audit->subject_id)->update(['name_en' => 'Unexpected concurrent value']);
            }
        });
        try {
            $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain('Post-import verification failed')->assertFailed();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_operation_failure_rolls_back_the_organization_and_prior_inserts(): void
    {
        $actor = $this->administrator();
        $options = $this->applyOptions($actor);
        $before = $this->snapshot();
        $event = 'eloquent.creating: '.Department::class;
        app('events')->listen($event, fn (): bool => false);
        try {
            $this->artisan('prd:import-xppl-master-data', $options)->expectsOutputToContain('Organization persistence was cancelled')->assertFailed();
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_malformed_source_json_is_rejected_without_writes(): void
    {
        $this->administrator();
        file_put_contents(database_path('seeders/xppl-master-data.json'), '{');
        $before = $this->snapshot();
        $this->artisan('prd:import-xppl-master-data')->expectsOutputToContain('Syntax error')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }
}
