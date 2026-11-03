<?php

namespace Tests\Feature;

use App\Models\AuthorizationScope;
use App\Models\DocumentCategory;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\RegisterConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IncomingOperationsMigrationTest extends TestCase
{
    public function test_phase_four_b_upgrade_and_rollback_preserve_existing_registered_metadata_and_protection(): void
    {
        $previous = DB::getDefaultConnection();
        $mysql = defined('DCMS_ISOLATED_MYSQL_TESTS');
        if (! $mysql) {
            config(['database.connections.incoming_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
            DB::setDefaultConnection('incoming_migration');
        }
        try {
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $profile = DocumentSecurityProfile::factory()->create();
            $scope = AuthorizationScope::context('site', $profile->company_id, $profile->site_id)->persist();
            $register = RegisterConfiguration::factory()->create(['scope_id' => $scope->id]);
            $category = DocumentCategory::factory()->create(['scope_id' => $scope->id]);
            $type = DocumentType::factory()->create(['scope_id' => $scope->id, 'category_id' => $category->id]);
            $type->registers()->attach($register->id, ['scope_id' => $scope->id]);
            $record = IncomingCorrespondence::query()->create(['scope_id' => $scope->id, 'profile_id' => $profile->id,
                'register_id' => $register->id, 'type_id' => $type->id, 'sender_organization' => 'Example sender', 'subject' => 'Preserved legacy record',
                'correspondence_date' => '2026-10-25', 'received_date' => '2026-10-26', 'priority' => 'normal', 'status' => 'registered',
                'internal_number' => 'MIGRATION/IN/2026/000001', 'registered_at' => now(), 'registered_by' => $profile->created_by,
                'created_by' => $profile->created_by, 'updated_by' => $profile->created_by]);
            $this->artisan('migrate:rollback', ['--step' => DB::table('migrations')->where('migration', '>=', '2026_10_26_031455_create_incoming_operations_tables')->count(), '--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->assertFalse(Schema::hasTable('incoming_correspondence_operations'));
            $this->assertFalse(Schema::hasColumn('incoming_correspondences', 'channel'));
            $before = $record->fresh()->getRawOriginal();
            $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->assertSame($before, array_diff_key($record->fresh()->getRawOriginal(), ['channel' => true, 'document_revision' => true]));
            $this->assertNull($record->fresh()->channel);
            $this->assertNull($record->fresh()->document_revision);
            $this->assertDatabaseCount('incoming_operation_audits', 0);
            try {
                DB::table('incoming_correspondences')->where('id', $record->id)->update(['internal_number' => 'FORGED']);
                $this->fail('Registered identity protection was lost during migration.');
            } catch (QueryException) {
            }
        } finally {
            if ($mysql) {
                $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
                RefreshDatabaseState::$migrated = true;
            } else {
                DB::disconnect('incoming_migration');
                DB::setDefaultConnection($previous);
            }
        }
    }
}
