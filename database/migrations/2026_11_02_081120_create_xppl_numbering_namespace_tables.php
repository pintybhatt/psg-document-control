<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xppl_numbering_namespaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('prefix', 100)->unique();
            $table->string('format', 32);
            $table->string('identity_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('xppl_numbering_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('namespace_id')->constrained('xppl_numbering_namespaces')->restrictOnDelete();
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('master_hash', 64);
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['series_id', 'department_id', 'type_id'], 'xppl_mapping_unique');
        });
        Schema::create('xppl_numbering_counters', function (Blueprint $table): void {
            $table->foreignId('namespace_id')->constrained('xppl_numbering_namespaces')->restrictOnDelete();
            $table->unsignedSmallInteger('period');
            $table->unsignedBigInteger('baseline');
            $table->unsignedBigInteger('value');
            $table->json('reconciliation');
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->primary(['namespace_id', 'period']);
        });
        Schema::create('xppl_numbering_allocations', function (Blueprint $table): void {
            $table->foreignId('issuance_id')->primary()->constrained('issued_document_numbers')->restrictOnDelete();
            $table->foreignId('namespace_id')->constrained('xppl_numbering_namespaces')->restrictOnDelete();
            $table->unsignedSmallInteger('period');
            $table->unsignedBigInteger('sequence');
            $table->string('format', 32);
            $table->unique(['namespace_id', 'period', 'sequence'], 'xppl_sequence_unique');
            $table->foreign(['namespace_id', 'period'])->references(['namespace_id', 'period'])->on('xppl_numbering_counters')->restrictOnDelete();
        });
        foreach (['xppl_numbering_namespaces', 'xppl_numbering_mappings', 'xppl_numbering_allocations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
        }
        $this->guard('xppl_numbering_namespaces', 'INSERT', 'EXISTS (SELECT 1 FROM xppl_numbering_namespaces WHERE id = NEW.id OR prefix = NEW.prefix)');
        $this->guard('xppl_numbering_mappings', 'INSERT', 'EXISTS (SELECT 1 FROM xppl_numbering_mappings WHERE id = NEW.id OR (series_id = NEW.series_id AND department_id = NEW.department_id AND type_id = NEW.type_id))');
        $this->guard('xppl_numbering_allocations', 'INSERT', 'EXISTS (SELECT 1 FROM xppl_numbering_allocations WHERE issuance_id = NEW.issuance_id OR (namespace_id = NEW.namespace_id AND period = NEW.period AND sequence = NEW.sequence))', 'no_replace');
        $this->guard('xppl_numbering_counters', 'DELETE', '1 = 1');
        $this->guard('xppl_numbering_counters', 'UPDATE', 'NEW.namespace_id != OLD.namespace_id OR NEW.period != OLD.period OR NEW.baseline != OLD.baseline OR NEW.value != OLD.value + 1 OR NEW.value > 999999999999 OR NEW.confirmed_by != OLD.confirmed_by OR NEW.confirmed_at != OLD.confirmed_at OR NEW.reconciliation != OLD.reconciliation');
        $this->guard('xppl_numbering_counters', 'INSERT', "NEW.value != NEW.baseline OR NEW.baseline < 0 OR NEW.value > 999999999999 OR NOT EXISTS (SELECT 1 FROM xppl_numbering_namespaces WHERE id = NEW.namespace_id AND ((format = 'xppl_controlled' AND NEW.period = 0) OR (format = 'xppl_letter' AND NEW.period BETWEEN 2000 AND 9999))) OR EXISTS (SELECT 1 FROM xppl_numbering_counters WHERE namespace_id = NEW.namespace_id AND period = NEW.period)");
        $this->guard('xppl_numbering_allocations', 'INSERT', 'NOT EXISTS (SELECT 1 FROM xppl_numbering_counters c JOIN xppl_numbering_namespaces n ON n.id = c.namespace_id WHERE c.namespace_id = NEW.namespace_id AND c.period = NEW.period AND c.value = NEW.sequence AND n.format = NEW.format)');
    }

    private function guard(string $table, string $event, string $condition, string $suffix = ''): void
    {
        $name = 'xppl_'.substr(md5($table.$event.$suffix), 0, 24);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected XPPL numbering state'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected XPPL numbering state'; END IF; END");
    }

    public function down(): void
    {
        if (DB::table('xppl_numbering_namespaces')->exists()) {
            throw new RuntimeException('XPPL numbering history requires a forward-only repair after configuration.');
        }
        Schema::dropIfExists('xppl_numbering_allocations');
        Schema::dropIfExists('xppl_numbering_counters');
        Schema::dropIfExists('xppl_numbering_mappings');
        Schema::dropIfExists('xppl_numbering_namespaces');
    }
};
