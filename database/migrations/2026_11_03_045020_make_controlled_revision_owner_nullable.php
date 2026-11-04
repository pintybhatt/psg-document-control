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
        $this->changeOwner(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('controlled_revisions')->whereNull('owner_id')->exists()) {
            throw new LogicException('Assign missing draft owners before rolling back optional owners.');
        }
        $this->changeOwner(false);
    }

    private function changeOwner(bool $nullable): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('controlled_revisions', fn (Blueprint $table) => $table->unsignedBigInteger('owner_id')->nullable($nullable)->change());

            return;
        }

        /** SQLite rebuilds this table; preserve its guards and guards referencing it atomically. */
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'");
        Schema::withoutForeignKeyConstraints(function () use ($nullable, $triggers): void {
            DB::transaction(function () use ($nullable, $triggers): void {
                foreach ($triggers as $trigger) {
                    DB::statement('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
                }
                Schema::table('controlled_revisions', fn (Blueprint $table) => $table->unsignedBigInteger('owner_id')->nullable($nullable)->change());
                foreach ($triggers as $trigger) {
                    DB::unprepared($trigger->sql);
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('Foreign key integrity failed while changing optional owners.');
                }
            });
        });
    }
};
