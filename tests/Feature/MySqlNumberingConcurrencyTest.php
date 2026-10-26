<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\DocumentNumbering;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\Fixtures\FormalRegistrationRecord;
use Tests\TestCase;

class MySqlNumberingConcurrencyTest extends TestCase
{
    use InteractsWithRbac;

    /** @var array<int, array{process: resource, pipes: array<int, resource>}> */
    private array $workers = [];

    private string $barrier;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('DCMS_ISOLATED_MYSQL_TESTS')) {
            $this->markTestSkipped('Requires the explicitly guarded isolated MySQL suite.');
        }
        $this->barrier = realpath(storage_path('framework/testing/mysql84')).'/worker-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (defined('DCMS_ISOLATED_MYSQL_TESTS')) {
            foreach ($this->workers as $worker) {
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
            if (isset($this->barrier)) {
                foreach (glob($this->barrier.'.*') as $file) {
                    unlink($file);
                }
            }
            DB::disconnect('mysql_observer');
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            RefreshDatabaseState::$migrated = true;
        }
        parent::tearDown();
    }

    /** @return array{User, AuthorizationScope, NumberingSeries} */
    private function fixture(): array
    {
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $actor = User::factory()->create();
        OrganizationMembership::factory()->for($actor)->atSite($site)->create();
        $this->grant($actor, $scope, ['numbering.issue', 'numbering.manage']);
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
        $series = app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'MYSQL/IN', 'padding' => 4, 'active' => true]);
        config(['database.connections.mysql_observer' => config('database.connections.mysql')]);

        return [$actor, $scope, $series];
    }

    private function issue(User $actor, AuthorizationScope $scope, NumberingSeries $series): IssuedDocumentNumber
    {
        return DB::transaction(fn (): IssuedDocumentNumber => app(DocumentNumbering::class)->register($actor, $scope, $series->id,
            fn (string $number): FormalRegistrationRecord => FormalRegistrationRecord::query()->create(['scope_id' => $scope->id,
                'register_id' => $series->register_id, 'state' => 'registered', 'internal_number' => $number])), 1);
    }

    private function worker(string $mode, User $actor, AuthorizationScope $scope, NumberingSeries $series, ?NumberingSeries $other = null): int
    {
        $id = count($this->workers);
        $command = [PHP_BINARY, base_path('tests/Fixtures/mysql-numbering-worker.php'), $mode, (string) $actor->id,
            (string) $scope->id, (string) $series->id, $this->barrier, (string) $id];
        if ($other) {
            $command[] = (string) $other->id;
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes];

        return $id;
    }

    private function ready(int $count): void
    {
        $deadline = microtime(true) + 20;
        while (count(glob($this->barrier.'.ready-*')) < $count && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertCount($count, glob($this->barrier.'.ready-*'), 'Workers must reach the barrier before release.');
    }

    /** @return array<string, mixed> */
    private function finish(int $id): array
    {
        $worker = $this->workers[$id];
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $status = proc_close($worker['process']);
        unset($this->workers[$id]);
        $this->assertSame(0, $status, $error);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_independent_processes_allocate_unique_committed_numbers_and_rollback_consumes_nothing(): void
    {
        [$actor, $scope, $series] = $this->fixture();
        for ($worker = 0; $worker < 4; $worker++) {
            $this->worker($worker === 3 ? 'rollback' : 'commit', $actor, $scope, $series);
        }
        $this->ready(4);
        touch($this->barrier.'.go');

        $results = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $results[] = $this->finish($worker);
        }

        $this->assertTrue($results[3]['rolled_back']);
        $this->assertCount(3, array_unique(array_column(array_slice($results, 0, 3), 'connection_id')));
        $this->assertSame([1, 2, 3], IssuedDocumentNumber::query()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(3, FormalRegistrationRecord::query()->count());
        $this->assertSame(3, IssuedDocumentNumber::query()->distinct()->count('number'));
        $this->assertSame(3, DB::table('numbering_counters')->sole()->value);
    }

    public function test_lock_timeout_aborts_before_callback_and_the_next_registration_reuses_the_available_sequence(): void
    {
        [$actor, $scope, $series] = $this->fixture();
        $worker = $this->worker('hold', $actor, $scope, $series);
        $this->ready(1);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->issue($actor, $scope, $series);
            $this->fail('Registration ignored a held register lock.');
        } catch (DeadlockException $exception) {
            $cause = $exception->getPrevious();
            $this->assertInstanceOf(QueryException::class, $cause);
            $this->assertSame(1205, (int) $cause->errorInfo[1]);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            touch($this->barrier.'.go');
        }
        $this->finish($worker);

        $this->assertDatabaseCount('issued_document_numbers', 1);
        $this->assertDatabaseCount('test_formal_numbering_records', 1);
        $this->assertSame(2, $this->issue($actor, $scope, $series)->sequence);
        $this->assertSame(2, DB::table('numbering_counters')->sole()->value);
    }

    public function test_configuration_change_waits_for_issuance_and_never_rewrites_its_committed_number(): void
    {
        [$actor, $scope, $series] = $this->fixture();
        $worker = $this->worker('hold', $actor, $scope, $series);
        $this->ready(1);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'MYSQL/NEW', 'padding' => 2, 'active' => true], (string) $series->id);
            $this->fail('Configuration changed while issuance held its series lock.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, (int) $exception->errorInfo[1]);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            touch($this->barrier.'.go');
        }
        $original = $this->finish($worker)['number'];
        app(ManageNumbering::class)->save($actor, $scope, ['prefix' => 'MYSQL/NEW', 'padding' => 2, 'active' => true], (string) $series->id);
        $next = $this->issue($actor, $scope, $series);

        $this->assertSame('MYSQL/NEW/'.now()->year.'/02', $next->number);
        $this->assertSame($original, IssuedDocumentNumber::query()->orderBy('id')->firstOrFail()->number);
        $this->assertDatabaseCount('numbering_configurations', 2);
    }

    public function test_deadlock_victim_retries_only_the_whole_transaction_without_consuming_rolled_back_numbers(): void
    {
        [$actor, $scope, $first] = $this->fixture();
        $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
        $second = app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'MYSQL/OUT', 'padding' => 4, 'active' => true]);
        $a = $this->worker('deadlock', $actor, $scope, $first, $second);
        $b = $this->worker('deadlock', $actor, $scope, $second, $first);
        $this->ready(2);
        touch($this->barrier.'.go');

        $results = [$this->finish($a), $this->finish($b)];

        $this->assertSame(3, array_sum(array_column($results, 'attempts')), 'Exactly one deadlock victim must retry its outer transaction.');
        $this->assertDatabaseCount('issued_document_numbers', 4);
        $this->assertDatabaseCount('test_formal_numbering_records', 4);
        foreach ([$first, $second] as $series) {
            $this->assertSame([1, 2], IssuedDocumentNumber::query()->where('series_id', $series->id)->orderBy('sequence')->pluck('sequence')->all());
        }
        $this->assertSame([2, 2], DB::table('numbering_counters')->orderBy('series_id')->pluck('value')->all());
    }

    #[TestWith(['REPEATABLE READ', true])]
    #[TestWith(['READ COMMITTED', false])]
    public function test_authorization_snapshot_visibility_matches_the_explicit_isolation_boundary(string $isolation, bool $visibleAfterRevocation): void
    {
        [$actor, $scope] = $this->fixture();
        $membership = $actor->memberships()->sole();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        DB::beginTransaction();
        try {
            $this->assertTrue(app(AccessControl::class)->allows($actor, 'numbering.issue', $scope));
            DB::connection('mysql_observer')->table('organization_memberships')->where('id', $membership->id)->update(['active' => false]);

            $this->assertSame($visibleAfterRevocation, app(AccessControl::class)->allows($actor, 'numbering.issue', $scope));
        } finally {
            DB::rollBack();
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->assertFalse(app(AccessControl::class)->allows($actor, 'numbering.issue', $scope));
    }

    public function test_mysql_schema_settings_and_phase_three_rollback_reapplication_preserve_phase_two_data(): void
    {
        [$actor] = $this->fixture();
        $settings = DB::selectOne('SELECT VERSION() AS version, @@version_comment AS edition, @@foreign_key_checks AS foreign_keys, @@sql_mode AS sql_mode, @@transaction_isolation AS isolation_level');
        $this->assertStringStartsWith('8.4.', $settings->version);
        $this->assertStringContainsString('Community', $settings->edition);
        $this->assertSame(1, $settings->foreign_keys);
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $settings->sql_mode);
        $this->assertSame('REPEATABLE-READ', $settings->isolation_level);
        $this->assertSame(['InnoDB'], array_values(array_unique(array_column(DB::select('SELECT ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'), 'ENGINE'))));

        $this->artisan('migrate:rollback', ['--step' => 6, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('numbering_series'));
        $this->assertFalse(Schema::hasTable('document_types'));
        $this->assertModelExists($actor);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('numbering_series'));
        $this->assertTrue(Schema::hasTable('document_types'));
        $this->assertDatabaseCount('organization_memberships', 1);
    }
}
