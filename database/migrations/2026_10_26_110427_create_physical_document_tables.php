<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('site_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('room', 100);
            $table->string('cabinet', 100)->nullable();
            $table->string('shelf', 100)->nullable();
            $table->string('box', 100)->nullable();
            $table->char('path_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['site_id', 'path_hash']);
        });
        Schema::create('physical_copies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('profile_id')->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('revision_id')->nullable()->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('site_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('identifier', 100);
            $table->enum('kind', ['original', 'copy']);
            $table->foreignId('location_id')->constrained('storage_locations')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('status', ['stored', 'checked_out', 'missing'])->default('stored');
            $table->foreignId('custodian_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->date('checkout_date')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['site_id', 'identifier']);
            $table->index(['profile_id', 'revision_id']);
            $table->index(['status', 'expected_return_date']);
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('storage_locations', $event, '1 = 1');
        }
        $this->guard('storage_locations', 'INSERT', 'NOT EXISTS (SELECT 1 FROM sites WHERE id = NEW.site_id AND company_id = NEW.company_id) OR (NEW.shelf IS NOT NULL AND NEW.cabinet IS NULL) OR (NEW.box IS NOT NULL AND NEW.shelf IS NULL)');
        $this->guard('physical_copies', 'DELETE', '1 = 1');
        $this->guard('physical_copies', 'UPDATE', 'NEW.id <> OLD.id OR NEW.profile_id <> OLD.profile_id OR NOT (NEW.revision_id <=> OLD.revision_id) OR NEW.site_id <> OLD.site_id OR NEW.identifier <> OLD.identifier OR NEW.kind <> OLD.kind OR NEW.lock_version <> OLD.lock_version + 1');
        $this->guard('physical_copies', 'INSERT', "NEW.status <> 'stored' OR NEW.lock_version <> 1");
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('physical_copies', $event, "NOT EXISTS (SELECT 1 FROM storage_locations l JOIN document_security_profiles p ON p.id = NEW.profile_id WHERE l.id = NEW.location_id AND l.company_id = p.company_id AND l.site_id = p.site_id AND NEW.site_id = p.site_id) OR NOT ((NEW.status = 'checked_out' AND NEW.custodian_id IS NOT NULL AND NEW.checkout_date IS NOT NULL AND NEW.expected_return_date IS NOT NULL AND NEW.expected_return_date >= NEW.checkout_date) OR (NEW.status IN ('stored', 'missing') AND NEW.custodian_id IS NULL AND NEW.checkout_date IS NULL AND NEW.expected_return_date IS NULL)) OR (NEW.revision_id IS NULL AND EXISTS (SELECT 1 FROM controlled_documents WHERE profile_id = NEW.profile_id)) OR (NEW.revision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM controlled_revisions r JOIN controlled_documents d ON d.id = r.document_id WHERE r.id = NEW.revision_id AND d.profile_id = NEW.profile_id)) OR (NOT EXISTS (SELECT 1 FROM incoming_correspondences WHERE profile_id = NEW.profile_id) AND NOT EXISTS (SELECT 1 FROM outgoing_correspondences WHERE profile_id = NEW.profile_id) AND NOT EXISTS (SELECT 1 FROM controlled_documents WHERE profile_id = NEW.profile_id))");
        }
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'custody_'.substr(md5($table.$event.$condition), 0, 24);
        if (DB::getDriverName() === 'sqlite') {
            $condition = str_replace('<=>', 'IS', $condition);
        }
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected physical custody'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected physical custody'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_copies');
        Schema::dropIfExists('storage_locations');
    }
};
