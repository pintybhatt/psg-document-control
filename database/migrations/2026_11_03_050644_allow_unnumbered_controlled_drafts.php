<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->changeCode(true);
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'controlled_code_required_'.strtolower($event);
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON controlled_documents WHEN NEW.registered_at IS NOT NULL AND NEW.document_code IS NULL BEGIN SELECT RAISE(ABORT, 'Registered document code required'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON controlled_documents FOR EACH ROW BEGIN IF NEW.registered_at IS NOT NULL AND NEW.document_code IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registered document code required'; END IF; END");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('controlled_documents')->whereNull('document_code')->exists()) {
            throw new LogicException('Register unnumbered drafts before rollback.');
        }
        $this->changeCode(false);
        foreach (['insert', 'update'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS controlled_code_required_'.$event);
        }
    }

    private function changeCode(bool $nullable): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('controlled_documents', fn (Blueprint $table) => $table->string('document_code', 100)->nullable($nullable)->change());

            return;
        }
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'");
        Schema::withoutForeignKeyConstraints(function () use ($nullable, $triggers): void {
            DB::transaction(function () use ($nullable, $triggers): void {
                foreach ($triggers as $trigger) {
                    DB::statement('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
                }
                Schema::table('controlled_documents', fn (Blueprint $table) => $table->string('document_code', 100)->nullable($nullable)->change());
                foreach ($triggers as $trigger) {
                    DB::unprepared($trigger->sql);
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('Controlled document foreign key integrity failed.');
                }
            });
        });
    }
};
