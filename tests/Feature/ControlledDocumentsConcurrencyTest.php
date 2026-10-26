<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageControlledDocuments;
use App\Authorization\ManageNumbering;
use App\Models\AuthorizationScope;
use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ControlledDocumentsConcurrencyTest extends TestCase
{
    use InteractsWithRbac;

    public function test_separate_connections_serialize_registration_revision_preparation_and_release_with_rollback(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $barrier = $directory.'/controlled-'.Str::uuid();
        $mysql = defined('DCMS_ISOLATED_MYSQL_TESTS');
        $previous = DB::getDefaultConnection();
        $database = $mysql ? 'guarded-mysql' : $barrier.'.sqlite';
        try {
            if (! $mysql) {
                touch($database);
                config(['database.connections.controlled_parallel' => ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED']]);
                DB::setDefaultConnection('controlled_parallel');
            }
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->definitions();
            $site = Site::factory()->create();
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
            $unit = new DocumentScope($site->company_id, $site->id);
            $actor = User::factory()->create();
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
            $this->grant($actor, $scope, ['controlled.drafts.manage', 'controlled.register', 'controlled.release', 'documents.metadata.view', 'numbering.issue', 'numbering.manage']);
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'controlled']);
            $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
            $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
            $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
            app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'CONCURRENT/CD', 'padding' => 6, 'active' => true]);
            $manager = app(ManageControlledDocuments::class);
            $records = [];
            for ($i = 0; $i < 3; $i++) {
                $records[] = $manager->save($actor, $unit, ['document_code' => 'CONCURRENT/SOP/'.$i, 'type_id' => $type->id, 'classification' => 'normal', 'title_en' => 'Concurrent controlled procedure',
                    'owner_id' => $actor->id, 'custodian_id' => $actor->id, 'issue_date' => '2026-10-25', 'effective_date' => '2026-10-26', 'revision_reason' => 'Initial', 'change_description' => 'Initial issue']);
            }
            $results = $this->race($mysql, $database, $actor, $site, $barrier, 'register', [[$records[0], false], [$records[0], false], [$records[1], false], [$records[2], true]]);
            $this->assertCount(2, array_column($results, 'success'));
            $this->assertCount(1, array_column($results, 'stale'));
            $this->assertTrue($results[3]['rollback']);
            $this->assertDatabaseCount('issued_document_numbers', 2);
            $this->assertSame(2, (int) DB::table('numbering_counters')->value('value'));
            $this->assertDatabaseHas('controlled_documents', ['id' => $records[2]->id, 'lock_version' => 1, 'internal_number' => null]);
            $record = $records[0]->refresh();
            $revision = $record->revisions()->sole();
            $record = $manager->release($actor, $unit, (string) $record->id, ['lock_version' => 2, 'revision_id' => $revision->id, 'reason' => 'Initial authorized release']);
            $results = $this->race($mysql, $database, $actor, $site, $barrier, 'revise', [[$record, false], [$record, false]]);
            $this->assertCount(1, array_column($results, 'success'));
            $this->assertCount(1, array_column($results, 'stale'));
            $this->assertSame(1, ControlledRevision::query()->where('document_id', $record->id)->whereNull('released_at')->count());
            $record->refresh();
            $draft = $record->revisions()->whereNull('released_at')->sole();
            $results = $this->race($mysql, $database, $actor, $site, $barrier, 'release', [[$record, true]], $draft->id);
            $this->assertTrue($results[0]['rollback']);
            $this->assertSame($revision->id, $record->refresh()->current_revision_id);
            $this->assertNull($draft->refresh()->released_at);
            $results = $this->race($mysql, $database, $actor, $site, $barrier, 'release', [[$record, false], [$record, false]], $draft->id);
            $this->assertCount(1, array_column($results, 'success'));
            $this->assertCount(1, array_column($results, 'stale'));
            $this->assertSame($draft->id, $record->refresh()->current_revision_id);
            $this->assertSame(5, $record->lock_version);
            $this->assertSame(2, $record->revisions()->whereNotNull('released_at')->count());
            $this->assertSame(2, (int) DB::table('numbering_counters')->value('value'));
            $this->assertSame(['created', 'registered', 'released', 'revision_prepared', 'released'], $record->audits()->pluck('action')->all());
        } finally {
            if ($mysql) {
                $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
                RefreshDatabaseState::$migrated = true;
            } else {
                DB::disconnect('controlled_parallel');
                DB::setDefaultConnection($previous);
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }

    /** @param list<array{ControlledDocument, bool}> $records
     * @return list<array<string, mixed>>
     */
    private function race(bool $mysql, string $database, User $actor, Site $site, string $barrier, string $operation, array $records, ?int $revisionId = null): array
    {
        foreach (glob($barrier.'.ready-*') as $file) {
            unlink($file);
        }
        if (is_file($barrier.'.go')) {
            unlink($barrier.'.go');
        }
        $processes = [];
        try {
            foreach ($records as $worker => [$record, $rollback]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/controlled-worker.php'), $mysql ? 'mysql' : 'sqlite', $database,
                    (string) $actor->id, (string) $site->company_id, (string) $site->id, (string) $record->id, $barrier, (string) $worker, $operation, (string) $record->lock_version, (string) $revisionId, $rollback ? 'yes' : 'no'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                unset($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob($barrier.'.ready-*')) < count($records) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(count($records), glob($barrier.'.ready-*'));
            file_put_contents($barrier.'.go', 'go');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $this->assertJson($output, $output);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $processes = [];

            return $results;
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
        }
    }
}
