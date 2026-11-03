<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['incoming', 'outgoing'] as $kind) {
            Schema::table($kind.'_correspondences', function (Blueprint $table): void {
                $table->string('document_revision', 30)->nullable();
            });
            $operations = $kind === 'incoming' ? 'incoming_correspondence_operations' : 'outgoing_operations';
            $action = $kind === 'incoming' ? 'updated' : 'note';
            $matches = [];
            foreach (['before' => 'OLD', 'after' => 'NEW'] as $side => $row) {
                $path = "'$.details.document_revision'";
                $value = DB::getDriverName() === 'sqlite'
                    ? "json_extract(a.`{$side}`, {$path})"
                    : "CASE WHEN JSON_TYPE(JSON_EXTRACT(a.`{$side}`, {$path})) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(a.`{$side}`, {$path})) END";
                $exists = DB::getDriverName() === 'sqlite'
                    ? "json_type(a.`{$side}`, {$path}) IS NOT NULL"
                    : "JSON_CONTAINS_PATH(a.`{$side}`, 'one', {$path}) = 1";
                $matches[] = '('.$exists.' AND '.$this->same($value, $row.'.document_revision').')';
            }
            $audit = "SELECT 1 FROM {$kind}_operation_audits a WHERE a.correspondence_id = OLD.id AND a.action = '{$action}' AND a.revision = COALESCE((SELECT o.revision FROM {$operations} o WHERE o.correspondence_id = OLD.id), 0) + 1 AND ".implode(' AND ', $matches);
            $condition = "OLD.status = 'registered' AND NOT (".$this->same('NEW.document_revision', 'OLD.document_revision').") AND NOT EXISTS ({$audit})";
            $name = 'audited_'.$kind.'_document_revision';
            DB::statement(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE UPDATE ON {$kind}_correspondences WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected correspondence revision'); END"
                : "CREATE TRIGGER {$name} BEFORE UPDATE ON {$kind}_correspondences FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected correspondence revision'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['incoming', 'outgoing'] as $kind) {
            DB::statement('DROP TRIGGER audited_'.$kind.'_document_revision');
            Schema::table($kind.'_correspondences', function (Blueprint $table): void {
                $table->dropColumn('document_revision');
            });
        }
    }

    private function same(string $left, string $right): string
    {
        return DB::getDriverName() === 'sqlite' ? "{$left} IS {$right}" : "CAST({$left} AS BINARY) <=> CAST({$right} AS BINARY)";
    }
};
