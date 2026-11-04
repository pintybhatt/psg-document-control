<?php

namespace Tests\Feature;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use SQLite3;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class PrepareXpplMasterDataTest extends TestCase
{
    use InteractsWithRbac;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/dcms-xppl-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/database.sqlite');
        $this->app->useDatabasePath($this->directory);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->directory.'/database.sqlite']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--path' => base_path('database/migrations'), '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function fixture(): User
    {
        $this->definitions();
        Company::factory()->create(['code' => 'DEMO']);
        $company = Company::factory()->create(['code' => 'XPPL']);
        Site::factory()->create(['company_id' => 1, 'code' => 'DEMO']);
        Site::factory()->create(['company_id' => $company->id, 'code' => 'XPPL']);
        Department::factory()->create(['company_id' => 2, 'site_id' => 2, 'code' => 'ADM', 'name_en' => 'Administration', 'name_lo' => 'Administration']);
        $demoScope = AuthorizationScope::context('site', 1, 1)->persist();
        DocumentCategory::factory()->create(['scope_id' => $demoScope->id, 'code' => 'CAT01', 'name_en' => 'Existing DEMO']);
        $actor = User::factory()->create();
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_id' => Role::where('code', 'SECURITY_ADMINISTRATOR')->value('id'), 'scope_id' => AuthorizationScope::context('global')->id, 'active' => true]);
        $this->app['env'] = 'local';

        return $actor;
    }

    /** @return array<string, mixed> */
    private function applyOptions(User $actor): array
    {
        return ['--apply' => true, '--approve' => hash_file('sha256', base_path('database/seeders/xppl-master-data.json')), '--actor' => $actor->id, '--backup' => $this->directory.'/backup.sqlite'];
    }

    public function test_dry_run_reports_exact_counts_without_writes_or_backups(): void
    {
        $this->fixture();
        $hash = hash_file('sha256', $this->directory.'/database.sqlite');

        $this->artisan('dev:prepare-xppl-master-data')->expectsOutput('departments: create=18, skip=1, conflict=0')
            ->expectsOutput('categories: create=39, skip=0, conflict=0')->expectsOutput('types: create=19, skip=0, conflict=0')->assertSuccessful();

        $this->assertSame($hash, hash_file('sha256', $this->directory.'/database.sqlite'));
        $this->assertFileDoesNotExist($this->directory.'/backup.sqlite');
        $this->assertDatabaseCount('departments', 1);
    }

    public function test_approved_apply_backs_up_and_imports_once_without_modifying_existing_data(): void
    {
        $actor = $this->fixture();
        $adm = Department::query()->sole()->getAttributes();
        $demo = DocumentCategory::query()->sole()->getAttributes();
        $options = $this->applyOptions($actor);
        $this->artisan('dev:prepare-xppl-master-data', $options)->assertSuccessful();

        $scope = AuthorizationScope::context('site', 2, 2);
        $this->assertSame(19, Department::query()->where('site_id', 2)->count());
        $this->assertSame(39, DocumentCategory::query()->where('scope_id', $scope->id)->count());
        $this->assertSame(19, DocumentType::query()->where('scope_id', $scope->id)->count());
        $this->assertDatabaseCount('register_configurations', 3);
        $this->assertSame($adm, Department::query()->findOrFail($adm['id'])->getAttributes());
        $this->assertSame($demo, DocumentCategory::query()->findOrFail($demo['id'])->getAttributes());
        $this->assertStringStartsWith('[DEV-UNTRANSLATED] ', DocumentType::query()->where('code', 'SDF')->sole()->name_lo);
        $this->assertSame(['outgoing'], DocumentType::query()->where('code', 'LTR')->sole()->registers->pluck('register_key')->map(fn ($key) => $key->value)->all());
        $backup = new SQLite3($options['--backup'], SQLITE3_OPEN_READONLY);
        $this->assertSame('ok', $backup->querySingle('PRAGMA integrity_check'));
        $this->assertSame(1, $backup->querySingle('SELECT COUNT(*) FROM departments'));
        $backup->close();
        $before = DB::table('master_data_audits')->count();
        $options['--backup'] = $this->directory.'/second-backup.sqlite';
        $this->artisan('dev:prepare-xppl-master-data', $options)->assertSuccessful();
        $this->assertSame($before, DB::table('master_data_audits')->count());
        foreach (['numbering_series', 'numbering_configurations', 'numbering_counters', 'issued_document_numbers', 'xppl_numbering_namespaces'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_missing_approval_or_backup_and_unauthorized_actor_cannot_apply(): void
    {
        $actor = $this->fixture();
        $options = $this->applyOptions($actor);
        $this->artisan('dev:prepare-xppl-master-data', array_replace($options, ['--approve' => 'not-approved']))->assertFailed();
        $this->artisan('dev:prepare-xppl-master-data', array_replace($options, ['--backup' => '']))->assertFailed();
        $user = User::factory()->create();
        $this->artisan('dev:prepare-xppl-master-data', array_replace($options, ['--actor' => $user->id]))->assertFailed();
        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('register_configurations', 0);
    }

    public function test_inactive_or_conflicting_existing_data_is_not_overwritten(): void
    {
        $actor = $this->fixture();
        Department::query()->sole()->update(['active' => false]);
        $this->artisan('dev:prepare-xppl-master-data', $this->applyOptions($actor))->assertFailed();
        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('register_configurations', 0);
        $this->assertFalse(Department::query()->sole()->active);
    }

    #[TestWith(['testing'])]
    #[TestWith(['production'])]
    public function test_nonlocal_environment_is_refused(string $environment): void
    {
        $this->fixture();
        $this->app['env'] = $environment;
        $this->artisan('dev:prepare-xppl-master-data')->assertFailed();
        $this->assertDatabaseCount('departments', 1);
    }
}
