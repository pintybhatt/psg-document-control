<?php

namespace Tests\Feature;

use App\Models\ControlledRevision;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class OptionalControlledOwnerMigrationTest extends TestCase
{
    use DatabaseMigrations;

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
