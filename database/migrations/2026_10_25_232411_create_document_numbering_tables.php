<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('register_id');
            $table->enum('purpose', ['internal_register', 'official_outgoing']);
            $table->enum('reset_policy', ['annual', 'continuous']);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['register_id', 'purpose'], 'numbering_purpose_unique');
            $table->index(['register_id', 'scope_id'], 'numbering_register_index');
            $table->foreign(['register_id', 'scope_id'], 'numbering_register_foreign')->references(['id', 'scope_id'])->on('register_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('numbering_namespaces', function (Blueprint $table): void {
            $table->string('prefix', 80)->primary();
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['prefix', 'series_id'], 'numbering_namespace_parent');
        });
        Schema::create('numbering_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('version');
            $table->string('prefix', 80);
            $table->unsignedTinyInteger('padding');
            $table->boolean('active');
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->unique(['series_id', 'version'], 'numbering_version_unique');
            $table->unique(['id', 'series_id'], 'numbering_configuration_parent');
            $table->index(['prefix', 'series_id'], 'numbering_prefix_index');
            $table->foreign(['prefix', 'series_id'], 'numbering_prefix_foreign')->references(['prefix', 'series_id'])->on('numbering_namespaces')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('numbering_counters', function (Blueprint $table): void {
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('period');
            $table->unsignedBigInteger('value');
            $table->primary(['series_id', 'period']);
        });
        Schema::create('issued_document_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('numbering_series')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('configuration_id');
            $table->unsignedSmallInteger('period');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('sequence');
            $table->string('number', 128)->unique();
            $table->string('record_type', 128);
            $table->string('record_key', 128);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['series_id', 'period', 'sequence'], 'numbering_sequence_unique');
            $table->unique(['series_id', 'record_type', 'record_key'], 'numbering_registration_unique');
            $table->index(['configuration_id', 'series_id'], 'numbering_issued_configuration_index');
            $table->foreign(['configuration_id', 'series_id'], 'numbering_issued_configuration_foreign')->references(['id', 'series_id'])->on('numbering_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['series_id', 'period'], 'numbering_issued_counter_foreign')->references(['series_id', 'period'])->on('numbering_counters')->restrictOnDelete()->restrictOnUpdate();
        });
        foreach (['numbering_namespaces', 'numbering_configurations', 'issued_document_numbers'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $table.'_'.$event, $event, '1 = 1');
            }
        }
        $this->guard('numbering_series', 'numbering_series_identity', 'UPDATE', 'NEW.id != OLD.id OR NEW.scope_id != OLD.scope_id OR NEW.register_id != OLD.register_id OR NEW.purpose != OLD.purpose OR NEW.reset_policy != OLD.reset_policy OR NEW.created_by != OLD.created_by');
        $this->guard('numbering_series', 'numbering_series_delete', 'DELETE', '1 = 1');
        $this->guard('numbering_series', 'numbering_outgoing_only', 'INSERT', "NEW.purpose = 'official_outgoing' AND NOT EXISTS (SELECT 1 FROM register_configurations WHERE id = NEW.register_id AND register_key = 'outgoing')");
        $this->guard('numbering_series', 'numbering_local_scope', 'INSERT', "NOT EXISTS (SELECT 1 FROM authorization_scopes WHERE id = NEW.scope_id AND scope_type IN ('company', 'site'))");
        $prefixInvalid = DB::getDriverName() === 'sqlite'
            ? "length(NEW.prefix) NOT BETWEEN 1 AND 80 OR NEW.prefix GLOB '*[^A-Z0-9_/-]*' OR substr(NEW.prefix, 1, 1) NOT GLOB '[A-Z]' OR NEW.prefix LIKE '%//%' OR substr(NEW.prefix, -1) = '/'"
            : "NOT REGEXP_LIKE(NEW.prefix, '^[A-Z][A-Z0-9_-]*(/[A-Z0-9][A-Z0-9_-]*)*$', 'c')";
        $this->guard('numbering_namespaces', 'numbering_prefix_valid', 'INSERT', $prefixInvalid);
        $this->guard('numbering_configurations', 'numbering_format_valid', 'INSERT', 'NEW.padding NOT BETWEEN 1 AND 12 OR NEW.version < 1 OR NEW.active NOT IN (0, 1)');
        $this->guard('numbering_configurations', 'numbering_history_no_replace', 'INSERT', 'EXISTS (SELECT 1 FROM numbering_configurations WHERE id = NEW.id OR (series_id = NEW.series_id AND version = NEW.version))');
        $this->guard('numbering_counters', 'numbering_counter_start', 'INSERT', "NEW.value != 0 OR NOT EXISTS (SELECT 1 FROM numbering_series WHERE id = NEW.series_id AND ((reset_policy = 'annual' AND NEW.period BETWEEN 2000 AND 9999) OR (reset_policy = 'continuous' AND NEW.period = 0)))");
        $this->guard('numbering_counters', 'numbering_counter_step', 'UPDATE', 'NEW.series_id != OLD.series_id OR NEW.period != OLD.period OR NEW.value != OLD.value + 1 OR NEW.value > 999999999999');
        $this->guard('numbering_counters', 'numbering_counter_delete', 'DELETE', '1 = 1');
        $this->guard('issued_document_numbers', 'numbering_issuance_valid', 'INSERT', 'NEW.year NOT BETWEEN 2000 AND 9999 OR NEW.sequence NOT BETWEEN 1 AND 999999999999 OR (NEW.period != 0 AND NEW.period != NEW.year) OR NOT EXISTS (SELECT 1 FROM numbering_counters WHERE series_id = NEW.series_id AND period = NEW.period AND value = NEW.sequence)');
        $this->guard('issued_document_numbers', 'numbering_issuance_no_replace', 'INSERT', 'EXISTS (SELECT 1 FROM issued_document_numbers WHERE id = NEW.id OR number = NEW.number OR (series_id = NEW.series_id AND period = NEW.period AND sequence = NEW.sequence) OR (series_id = NEW.series_id AND record_type = NEW.record_type AND record_key = NEW.record_key))');
    }

    private function guard(string $table, string $name, string $event, string $invalid): void
    {
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable numbering state'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable numbering state'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_document_numbers');
        Schema::dropIfExists('numbering_counters');
        Schema::dropIfExists('numbering_configurations');
        Schema::dropIfExists('numbering_namespaces');
        Schema::dropIfExists('numbering_series');
    }
};
