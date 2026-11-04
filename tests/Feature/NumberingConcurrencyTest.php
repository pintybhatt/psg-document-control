<?php

namespace Tests\Feature;

use App\Authorization\DocumentNumbering;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\IssuedDocumentNumber;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\Fixtures\FormalRegistrationRecord;
use Tests\TestCase;

class NumberingConcurrencyTest extends TestCase
{
    use InteractsWithRbac;

    /** @param array{pattern: string}|null $format */
    #[TestWith([null])]
    #[TestWith([['pattern' => 'CONCURRENCY-{year2}-{sequence}']])]
    public function test_real_separate_sqlite_processes_serialize_registration_and_a_rollback_consumes_nothing(?array $format): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $database = $directory.'/numbering-'.Str::uuid().'.sqlite';
        touch($database);
        $previous = DB::getDefaultConnection();
        config(['database.connections.numbering_parallel' => ['driver' => 'sqlite', 'database' => $database,
            'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED']]);
        $processes = [];
        try {
            DB::setDefaultConnection('numbering_parallel');
            $this->artisan('migrate', ['--database' => 'numbering_parallel', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->definitions();
            FormalRegistrationRecord::createTestTable();
            $site = Site::factory()->create();
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
            $actor = User::factory()->create();
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
            $this->grant($actor, $scope, ['numbering.issue', 'numbering.manage']);
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
            $series = app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'CONCURRENCY/TEST', 'padding' => 6, 'format' => $format, 'active' => true]);
            $this->assertSame(0, DB::transactionLevel());
            try {
                app(DocumentNumbering::class)->register($actor, $scope, $series->id, fn () => throw new \RuntimeException('Callback must not run'));
                $this->fail('Issuance was allowed outside registration transaction.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('caller-owned', $exception->getMessage());
            }
            for ($worker = 0; $worker < 4; $worker++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/numbering-worker.php'), $database, (string) $actor->id, (string) $scope->id, (string) $series->id, (string) $register->id, (string) $worker],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while (count(glob($database.'.ready-*')) < 4 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(4, glob($database.'.ready-*'), 'All workers must be ready before releasing the barrier.');
            file_put_contents($database.'.start', 'start');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                $this->assertSame(0, $status, $error);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $processes = [];
            $this->assertTrue($results[3]['rolled_back']);
            $sequences = array_column($results, 'sequence');
            sort($sequences);
            $this->assertSame([1, 2, 3], $sequences);
            $this->assertSame(3, IssuedDocumentNumber::query()->count());
            $this->assertSame(3, FormalRegistrationRecord::query()->count());
            $this->assertSame(3, DB::table('numbering_counters')->sole()->value);
            $this->assertSame(3, IssuedDocumentNumber::query()->distinct()->count('number'));
            if ($format !== null) {
                $this->assertMatchesRegularExpression('/^CONCURRENCY-\d{2}-00000[1-3]$/', $results[0]['number']);
            }
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            DB::disconnect('numbering_parallel');
            DB::setDefaultConnection($previous);
            foreach (glob($database.'*') as $file) {
                unlink($file);
            }
        }
    }
}
