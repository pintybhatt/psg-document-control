<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class IncomingCorrespondenceConcurrencyTest extends TestCase
{
    use InteractsWithRbac;

    public function test_separate_connections_serialize_duplicate_registration_and_roll_back_failed_registration(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $barrier = $directory.'/incoming-'.Str::uuid();
        $mysql = defined('DCMS_ISOLATED_MYSQL_TESTS');
        $previous = DB::getDefaultConnection();
        $database = $mysql ? 'guarded-mysql' : $barrier.'.sqlite';
        $processes = [];
        try {
            if (! $mysql) {
                touch($database);
                config(['database.connections.incoming_parallel' => ['driver' => 'sqlite', 'database' => $database,
                    'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED']]);
                DB::setDefaultConnection('incoming_parallel');
            }
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->definitions();
            $site = Site::factory()->create();
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
            $actor = User::factory()->create();
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
            $this->grant($actor, $scope, ['incoming.drafts.manage', 'documents.metadata.view', 'incoming.register', 'numbering.issue', 'numbering.manage']);
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'incoming']);
            $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
            $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
            $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
            app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'CONCURRENT/IN', 'padding' => 6, 'active' => true]);
            $drafts = [];
            for ($i = 0; $i < 3; $i++) {
                $drafts[] = app(ManageIncomingCorrespondence::class)->save($actor, new DocumentScope($site->company_id, $site->id), [
                    'type_id' => $type->id, 'subject' => 'Synthetic concurrent draft', 'sender_organization' => 'Example sender',
                    'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'priority' => 'normal', 'classification' => 'normal',
                ]);
            }
            // Two workers race for one draft, another succeeds, and a fourth rolls back after issuance.
            $records = [$drafts[0], $drafts[0], $drafts[1], $drafts[2]];
            foreach ($records as $worker => $record) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/incoming-worker.php'), $mysql ? 'mysql' : 'sqlite', $database,
                    (string) $actor->id, (string) $site->company_id, (string) $site->id, (string) $record->id, $barrier, (string) $worker, $worker === 3 ? 'yes' : 'no'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                unset($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob($barrier.'.ready-*')) < 4 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(4, glob($barrier.'.ready-*'));
            file_put_contents($barrier.'.go', 'go');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $processes = [];
            $this->assertCount(2, array_column($results, 'number'));
            $this->assertSame([true], array_column($results, 'duplicate'));
            $this->assertTrue($results[3]['rollback']);
            $this->assertSame(2, IncomingCorrespondence::query()->where('status', 'registered')->count());
            $this->assertDatabaseCount('issued_document_numbers', 2);
            $this->assertDatabaseCount('incoming_registration_links', 2);
            $this->assertDatabaseCount('incoming_correspondence_audits', 5);
            $this->assertSame(2, (int) DB::table('numbering_counters')->value('value'));
            $this->assertDatabaseHas('incoming_correspondences', ['id' => $drafts[2]->id, 'status' => 'draft', 'internal_number' => null]);
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
                DB::disconnect('incoming_parallel');
                DB::setDefaultConnection($previous);
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }
}
