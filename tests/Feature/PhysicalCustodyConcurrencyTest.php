<?php

namespace Tests\Feature;

use App\Authorization\ManagePhysicalCustody;
use App\Models\PhysicalCopy;
use App\Models\StorageEvent;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class PhysicalCustodyConcurrencyTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac;

    public function test_independent_connections_cannot_checkout_the_same_copy_twice(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $barrier = $directory.'/custody-'.Str::uuid();
        $mysql = defined('DCMS_ISOLATED_MYSQL_TESTS');
        $previous = DB::getDefaultConnection();
        $database = $mysql ? 'guarded-mysql' : $barrier.'.sqlite';
        $processes = [];
        try {
            if (! $mysql) {
                touch($database);
                config(['database.connections.custody_parallel' => ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED']]);
                DB::setDefaultConnection('custody_parallel');
            }
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            [$record, $actor] = $this->fixture();
            $manager = app(ManagePhysicalCustody::class);
            $scope = $record->profile->documentScope();
            $location = $manager->location($actor, $scope, 'incoming', (string) $record->id, ['room' => 'Archive']);
            $copy = $manager->create($actor, $scope, 'incoming', (string) $record->id, null, ['identifier' => 'CONCURRENT-001', 'kind' => 'original', 'location_id' => $location->id, 'reason' => 'Initial']);
            for ($worker = 0; $worker < 2; $worker++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/custody-worker.php'), $mysql ? 'mysql' : 'sqlite', $database, (string) $actor->id, (string) $scope->companyId, (string) $scope->siteId, (string) $record->id, $copy->id, $barrier, (string) $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                unset($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob($barrier.'.ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.ready-*'));
            file_put_contents($barrier.'.go', 'go');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[] = $output;
            }
            $processes = [];
            sort($results);
            $this->assertSame(['stale', 'success'], $results);
            $this->assertSame('checked_out', $copy->refresh()->status);
            $this->assertSame(2, $copy->lock_version);
            $this->assertSame(1, PhysicalCopy::whereNotNull('custodian_id')->count());
            $this->assertSame(1, StorageEvent::where('action', 'custody.checkout')->count());
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
            if ($mysql) {
                $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
                RefreshDatabaseState::$migrated = true;
            } else {
                DB::disconnect('custody_parallel');
                DB::setDefaultConnection($previous);
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }
}
