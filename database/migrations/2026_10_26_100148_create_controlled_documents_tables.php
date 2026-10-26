<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('profile_id')->unique()->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('register_id');
            $table->foreignId('type_id');
            $table->foreign(['register_id', 'scope_id'])->references(['id', 'scope_id'])->on('register_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'scope_id'])->references(['id', 'scope_id'])->on('document_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'register_id'])->references(['type_id', 'register_id'])->on('document_type_register')->restrictOnDelete()->restrictOnUpdate();
            $table->string('document_code', 100);
            $table->unique(['scope_id', 'document_code']);
            $table->string('internal_number', 191)->nullable()->unique();
            $table->enum('status', ['draft', 'current', 'superseded', 'obsolete', 'archived'])->default('draft');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('controlled_documents')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['scope_id', 'status', 'id']);
        });
        Schema::create('controlled_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('controlled_documents')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('revision_number');
            $table->unsignedTinyInteger('draft_slot')->nullable()->default(1);
            $table->unique(['document_id', 'revision_number']);
            $table->unique(['document_id', 'draft_slot']);
            $table->string('title_en', 500);
            $table->string('title_lo', 500)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_lo')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('custodian_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('owner_name')->nullable();
            $table->string('custodian_name')->nullable();
            $table->date('issue_date');
            $table->date('effective_date');
            $table->date('review_date')->nullable();
            $table->text('revision_reason');
            $table->text('change_description');
            $table->foreignId('supersedes_id')->nullable()->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('release_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
        });
        Schema::create('controlled_registration_links', function (Blueprint $table): void {
            $table->foreignId('document_id')->primary()->constrained('controlled_documents')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('issuance_id')->unique()->constrained('issued_document_numbers')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('controlled_document_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('controlled_documents')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('revision_id')->nullable()->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->json('changed_fields');
            $table->timestamps();
        });
        foreach (['controlled_registration_links', 'controlled_document_audits'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
        }
        $this->guard('controlled_document_audits', 'INSERT', 'EXISTS (SELECT 1 FROM controlled_document_audits WHERE id = NEW.id)');
        $this->guard('controlled_registration_links', 'INSERT', "EXISTS (SELECT 1 FROM controlled_registration_links WHERE document_id = NEW.document_id OR issuance_id = NEW.issuance_id) OR NOT EXISTS (SELECT 1 FROM controlled_documents d JOIN issued_document_numbers i ON i.id = NEW.issuance_id JOIN numbering_series s ON s.id = i.series_id WHERE d.id = NEW.document_id AND d.internal_number = i.number AND i.record_type = 'controlled' AND i.record_key = CAST(d.id AS CHAR) AND s.purpose = 'internal_register' AND s.register_id = d.register_id AND s.scope_id = d.scope_id)");
        $this->guard('controlled_documents', 'DELETE', '1 = 1');
        $this->guard('controlled_documents', 'INSERT', "NEW.status <> 'draft' OR NEW.registered_at IS NOT NULL OR NEW.internal_number IS NOT NULL OR NEW.current_revision_id IS NOT NULL OR NEW.replaced_by_id IS NOT NULL OR EXISTS (SELECT 1 FROM controlled_documents WHERE id = NEW.id OR profile_id = NEW.profile_id)");
        $this->guard('controlled_documents', 'UPDATE', "NEW.id <> OLD.id OR NEW.scope_id <> OLD.scope_id OR NEW.profile_id <> OLD.profile_id OR NEW.register_id <> OLD.register_id OR NEW.created_by <> OLD.created_by OR NEW.lock_version <> OLD.lock_version + 1 OR (OLD.registered_at IS NOT NULL AND (NEW.document_code <> OLD.document_code OR NEW.type_id <> OLD.type_id OR NOT (NEW.internal_number <=> OLD.internal_number) OR NOT (NEW.registered_at <=> OLD.registered_at) OR NOT (NEW.registered_by <=> OLD.registered_by))) OR (OLD.status IN ('obsolete', 'superseded', 'archived') AND (NOT (NEW.current_revision_id <=> OLD.current_revision_id) OR NOT (NEW.replaced_by_id <=> OLD.replaced_by_id))) OR NOT ((OLD.status = 'draft' AND NEW.status IN ('draft', 'current')) OR (OLD.status = 'current' AND NEW.status IN ('current', 'obsolete', 'superseded')) OR (OLD.status IN ('obsolete', 'superseded') AND NEW.status = 'archived'))");
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('controlled_documents', $event, "NOT ((NEW.registered_at IS NULL AND NEW.internal_number IS NULL AND NEW.registered_by IS NULL AND NEW.status = 'draft') OR (NEW.registered_at IS NOT NULL AND NEW.internal_number IS NOT NULL AND NEW.registered_by IS NOT NULL)) OR NOT EXISTS (SELECT 1 FROM register_configurations r JOIN authorization_scopes s ON s.id = r.scope_id JOIN document_security_profiles p ON p.id = NEW.profile_id WHERE r.id = NEW.register_id AND r.scope_id = NEW.scope_id AND r.register_key = 'controlled' AND s.scope_type = 'site' AND p.resource_type = 'controlled' AND p.company_id = s.company_id AND p.site_id = s.site_id) OR (NEW.current_revision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM controlled_revisions v WHERE v.id = NEW.current_revision_id AND v.document_id = NEW.id AND v.released_at IS NOT NULL)) OR (NEW.status <> 'draft' AND NEW.current_revision_id IS NULL) OR (NEW.status = 'draft' AND NEW.current_revision_id IS NOT NULL) OR (NEW.status = 'superseded' AND NEW.replaced_by_id IS NULL) OR (NEW.replaced_by_id IS NOT NULL AND (NEW.replaced_by_id = NEW.id OR NEW.status NOT IN ('superseded', 'archived') OR NOT EXISTS (SELECT 1 FROM controlled_documents d WHERE d.id = NEW.replaced_by_id AND d.scope_id = NEW.scope_id)))");
        }
        $this->guard('controlled_documents', 'UPDATE', 'NOT (NEW.current_revision_id <=> OLD.current_revision_id) AND (NEW.current_revision_id IS NULL OR NOT EXISTS (SELECT 1 FROM controlled_revisions v WHERE v.id = NEW.current_revision_id AND v.document_id = OLD.id AND v.released_at IS NOT NULL AND (v.supersedes_id <=> OLD.current_revision_id)))');
        $this->guard('controlled_revisions', 'DELETE', '1 = 1');
        $this->guard('controlled_revisions', 'UPDATE', 'OLD.released_at IS NOT NULL OR NEW.id <> OLD.id OR NEW.document_id <> OLD.document_id OR NEW.revision_number <> OLD.revision_number OR NEW.created_by <> OLD.created_by OR NOT (NEW.supersedes_id <=> OLD.supersedes_id)');
        $this->guard('controlled_revisions', 'INSERT', 'NEW.released_at IS NOT NULL OR EXISTS (SELECT 1 FROM controlled_revisions WHERE id = NEW.id OR (document_id = NEW.document_id AND revision_number = NEW.revision_number))');
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('controlled_revisions', $event, "NOT ((NEW.released_at IS NULL AND (NEW.draft_slot <=> 1) AND NEW.released_by IS NULL AND NEW.release_reason IS NULL) OR (NEW.released_at IS NOT NULL AND NEW.draft_slot IS NULL AND NEW.released_by IS NOT NULL AND NEW.release_reason IS NOT NULL AND NEW.owner_name IS NOT NULL AND NEW.custodian_name IS NOT NULL)) OR NEW.effective_date < NEW.issue_date OR (NEW.review_date IS NOT NULL AND NEW.review_date < NEW.effective_date) OR NOT EXISTS (SELECT 1 FROM controlled_documents d WHERE d.id = NEW.document_id AND d.status IN ('draft', 'current') AND (NEW.released_at IS NULL OR d.registered_at IS NOT NULL) AND (NEW.supersedes_id <=> d.current_revision_id)) OR (NEW.revision_number = 0 AND NEW.supersedes_id IS NOT NULL) OR (NEW.revision_number > 0 AND NOT EXISTS (SELECT 1 FROM controlled_revisions v WHERE v.id = NEW.supersedes_id AND v.document_id = NEW.document_id AND v.revision_number = NEW.revision_number - 1 AND v.released_at IS NOT NULL))");
        }
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'controlled_'.substr(md5($table.$event.$condition), 0, 24);
        if (DB::getDriverName() === 'sqlite') {
            $condition = str_replace('<=>', 'IS', $condition);
        }
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected controlled document'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected controlled document'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_document_audits');
        Schema::dropIfExists('controlled_registration_links');
        Schema::dropIfExists('controlled_revisions');
        Schema::dropIfExists('controlled_documents');
    }
};
