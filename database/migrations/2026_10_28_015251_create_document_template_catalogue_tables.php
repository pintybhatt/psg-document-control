<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name_en', 255);
            $table->string('name_lo', 255);
            $table->text('description_en')->nullable();
            $table->text('description_lo')->nullable();
            $table->string('purpose', 100);
            $table->foreignUuid('attachment_version_id')->nullable()->constrained('attachment_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('status', ['inactive', 'retired'])->default('inactive');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('retired_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestamps();
            $table->index(['scope_id', 'id']);
        });
        Schema::create('document_template_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('document_templates')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('action', ['created', 'retired']);
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'action']);
        });
        $this->guard('document_templates', 'INSERT', "NEW.status != 'inactive' OR NEW.retired_by IS NOT NULL OR NEW.retired_at IS NOT NULL OR NEW.retirement_reason IS NOT NULL OR EXISTS (SELECT 1 FROM document_templates WHERE id = NEW.id) OR NOT EXISTS (SELECT 1 FROM authorization_scopes WHERE id = NEW.scope_id AND scope_type = 'site') OR (NEW.attachment_version_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM attachment_versions v JOIN document_attachments a ON a.id = v.attachment_id JOIN controlled_revisions r ON r.id = a.revision_id JOIN controlled_documents d ON d.id = r.document_id WHERE v.id = NEW.attachment_version_id AND a.profile_id = d.profile_id AND d.scope_id = NEW.scope_id AND r.released_at IS NOT NULL AND LOWER(v.original_name) LIKE '%.docx' AND v.mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))");
        $this->guard('document_templates', 'UPDATE', "OLD.status != 'inactive' OR NEW.status != 'retired' OR NEW.retired_by IS NULL OR NEW.retired_at IS NULL OR NEW.retirement_reason IS NULL OR NEW.retirement_reason = '' OR NEW.id != OLD.id OR NEW.scope_id != OLD.scope_id OR NEW.name_en != OLD.name_en OR NEW.name_lo != OLD.name_lo OR NOT (NEW.description_en <=> OLD.description_en) OR NOT (NEW.description_lo <=> OLD.description_lo) OR NEW.purpose != OLD.purpose OR NOT (NEW.attachment_version_id <=> OLD.attachment_version_id) OR NEW.created_by != OLD.created_by OR NOT (NEW.created_at <=> OLD.created_at)");
        $this->guard('document_templates', 'DELETE', '1 = 1');
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('document_template_audits', $event, '1 = 1');
        }
        $this->guard('document_template_audits', 'INSERT', "EXISTS (SELECT 1 FROM document_template_audits WHERE id = NEW.id OR (template_id = NEW.template_id AND action = NEW.action)) OR NOT EXISTS (SELECT 1 FROM document_templates WHERE id = NEW.template_id AND ((NEW.action = 'created' AND status = 'inactive') OR (NEW.action = 'retired' AND status = 'retired')))");
    }

    private function guard(string $table, string $event, string $invalid): void
    {
        $name = $table.'_'.strtolower($event);
        $invalid = DB::getDriverName() === 'sqlite' ? str_replace('<=>', 'IS', $invalid) : $invalid;
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Protected template catalogue'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected template catalogue'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_audits');
        Schema::dropIfExists('document_templates');
    }
};
