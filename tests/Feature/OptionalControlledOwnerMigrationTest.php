<?php

namespace Tests\Feature;

use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class OptionalControlledOwnerMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_unnumbered_draft_migration_preserves_codes_guards_and_blocks_unsafe_rollback(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite rebuild regression.');
        }
        $document = ControlledDocument::factory()->create()->refresh();
        $before = $document->getAttributes();
        $guards = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name");
        $migration = require database_path('migrations/2026_11_03_050644_allow_unnumbered_controlled_drafts.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, $document->fresh()->getAttributes());
        $this->assertEquals($guards, DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name"));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $document->update(['document_code' => null, 'lock_version' => 2]);
        try {
            $migration->down();
            $this->fail('Rollback must refuse unnumbered drafts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Register unnumbered drafts', $exception->getMessage());
        }
        $document->update(['document_code' => $before['document_code'], 'lock_version' => 3]);
    }

    public function test_sqlite_owner_change_preserves_records_foreign_keys_and_all_guards(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite table rebuild regression.');
        }
        $revision = ControlledRevision::factory()->create();
        $revision->refresh();
        $before = $revision->getAttributes();
        $guards = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name");
        $migration = require database_path('migrations/2026_11_03_045020_make_controlled_revision_owner_nullable.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, $revision->fresh()->getAttributes());
        $this->assertEquals($guards, DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name"));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $revision->update(['owner_id' => null]);
        try {
            $migration->down();
            $this->fail('Rollback must refuse missing owners.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Assign missing draft owners', $exception->getMessage());
        }
        $this->assertNull($revision->fresh()->owner_id);
        $revision->update(['owner_id' => $before['owner_id']]);

    }
}
