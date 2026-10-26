<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['document_attachments', 'attachment_versions', 'storage_events', 'physical_copies', 'storage_locations'] as $table) {
            $name = 'vault_insert_'.$table;
            $condition = "EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id)";
            if ($table === 'physical_copies') {
                $condition .= ' OR EXISTS (SELECT 1 FROM physical_copies WHERE site_id = NEW.site_id AND identifier = NEW.identifier)';
            }
            if ($table === 'storage_locations') {
                $condition .= ' OR EXISTS (SELECT 1 FROM storage_locations WHERE site_id = NEW.site_id AND path_hash = NEW.path_hash)';
            }
            DB::statement(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Immutable storage identity'); END"
                : "CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable storage identity'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['document_attachments', 'attachment_versions', 'storage_events', 'physical_copies', 'storage_locations'] as $table) {
            DB::statement('DROP TRIGGER IF EXISTS vault_insert_'.$table);
        }
    }
};
