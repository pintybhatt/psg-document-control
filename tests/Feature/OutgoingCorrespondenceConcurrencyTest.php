<?php

namespace Tests\Feature;

use App\Authorization\DocumentScope;
use App\Authorization\ManageNumbering;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Authorization\XpplNumbering;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\OrganizationMembership;
use App\Models\OutgoingCorrespondence;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class OutgoingCorrespondenceConcurrencyTest extends TestCase
{
    use InteractsWithRbac;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_separate_connections_serialize_duplicate_registration_and_roll_back_failed_registration(bool $xppl): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $barrier = $directory.'/outgoing-'.Str::uuid();
        $mysql = defined('DCMS_ISOLATED_MYSQL_TESTS');
        $previous = DB::getDefaultConnection();
        $database = $mysql ? 'guarded-mysql' : $barrier.'.sqlite';
        $processes = [];
        try {
            if (! $mysql) {
                touch($database);
                config(['database.connections.outgoing_parallel' => ['driver' => 'sqlite', 'database' => $database,
                    'foreign_key_constraints' => true, 'busy_timeout' => 10000, 'transaction_mode' => 'DEFERRED']]);
                DB::setDefaultConnection('outgoing_parallel');
            }
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->definitions();
            $site = Site::factory()->create();
            if ($xppl) {
                Company::query()->whereKey($site->company_id)->update(['code' => 'XPPL']);
            }
            $department = $xppl ? Department::factory()->create(['company_id' => $site->company_id, 'site_id' => $site->id, 'code' => 'ADM']) : null;
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
            $actor = User::factory()->create();
            OrganizationMembership::factory()->for($actor)->atSite($site)->create();
            if ($department) {
                OrganizationMembership::factory()->for($actor)->inDepartment($department)->create();
            }
            if ($xppl) {
                OrganizationMembership::factory()->for($actor)->create(['company_id' => $site->company_id]);
                $companyScope = AuthorizationScope::context('company', $site->company_id)->persist();
                $this->grant($actor, $companyScope, ['numbering.manage']);
                app(XpplNumbering::class)->activatePolicy($actor, $companyScope, ['register_kind' => 'outgoing', 'confirm' => true]);
            }
            $this->grant($actor, $scope, ['outgoing.drafts.manage', 'documents.metadata.view', 'outgoing.register', 'numbering.issue', 'numbering.manage']);
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id, 'register_key' => 'outgoing']);
            $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
            $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id] + ($xppl ? ['code' => 'LTR'] : []));
            $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
            if (! $xppl) {
                app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual', 'prefix' => 'CONCURRENT/OUT', 'padding' => 6, 'active' => true]);
                app(ManageNumbering::class)->save($actor, $scope, ['register_id' => $register->id, 'purpose' => 'official_outgoing', 'reset_policy' => 'annual', 'prefix' => 'CONCURRENT/OFFICIAL', 'padding' => 6, 'active' => true]);
            }
            $drafts = [];
            for ($i = 0; $i < 3; $i++) {
                $drafts[] = app(ManageOutgoingCorrespondence::class)->save($actor, new DocumentScope($site->company_id, $site->id, $department?->id), [
                    'type_id' => $type->id, 'subject' => 'Synthetic concurrent draft', 'signatory_name' => 'Example signatory', 'recipients' => [['kind' => 'primary', 'organization' => 'Example recipient']],
                    'document_date' => '2026-10-25', 'priority' => 'normal', 'classification' => 'normal',
                ]);
            }
            // Two workers race for one draft, another succeeds, and a fourth rolls back after issuance.
            $records = [$drafts[0], $drafts[0], $drafts[1], $drafts[2]];
            foreach ($records as $worker => $record) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/outgoing-worker.php'), $mysql ? 'mysql' : 'sqlite', $database,
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
            $this->assertCount($xppl ? 3 : 2, array_column($results, 'number'));
            $this->assertSame($xppl ? [] : [true], array_column($results, 'duplicate'));
            $this->assertTrue($results[3]['rollback']);
            $this->assertSame(2, OutgoingCorrespondence::query()->where('status', 'registered')->count());
            $this->assertDatabaseCount('issued_document_numbers', 4);
            $this->assertDatabaseCount('outgoing_registration_links', 4);
            $this->assertDatabaseCount('outgoing_correspondence_audits', 5);
            $this->assertSame([2, 2], DB::table('numbering_counters')->orderBy('series_id')->pluck('value')->map(fn (mixed $value): int => (int) $value)->all());
            if ($xppl) {
                $this->assertDatabaseCount('xppl_numbering_allocations', 4);
                $this->assertSame([2, 2], DB::table('xppl_numbering_counters')->orderBy('namespace_id')->pluck('value')->map(fn (mixed $value): int => (int) $value)->all());
                $this->assertSame(['XPPL-OUT-'.now()->format('Y').'-0001', 'XPPL-OUT-'.now()->format('Y').'-0002'], OutgoingCorrespondence::query()->whereNotNull('registered_at')->orderBy('internal_number')->pluck('internal_number')->all());
            }
            $this->assertCount(2, array_unique(array_column($results, 'official_number')));
            $this->assertCount(2, array_unique(array_column($results, 'number')));
            $this->assertDatabaseHas('outgoing_correspondences', ['id' => $drafts[2]->id, 'status' => 'draft', 'internal_number' => null, 'official_number' => null]);
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
                DB::disconnect('outgoing_parallel');
                DB::setDefaultConnection($previous);
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }
}
