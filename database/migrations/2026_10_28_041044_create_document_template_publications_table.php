<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->unique()->constrained('document_templates')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignUuid('attachment_version_id')->constrained('attachment_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('approval_submission_id')->constrained('document_approval_submissions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('published_at');
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamps();
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard($event, '1 = 1');
        }
        $this->guard('INSERT', "EXISTS (SELECT 1 FROM document_template_publications WHERE id = NEW.id OR template_id = NEW.template_id) OR NOT EXISTS (SELECT 1 FROM document_templates t JOIN attachment_versions v ON v.id = t.attachment_version_id JOIN document_attachments a ON a.id = v.attachment_id JOIN controlled_revisions r ON r.id = a.revision_id JOIN controlled_documents d ON d.id = r.document_id JOIN document_approval_submissions s ON s.id = NEW.approval_submission_id WHERE t.id = NEW.template_id AND t.status = 'inactive' AND t.attachment_version_id = NEW.attachment_version_id AND a.profile_id = d.profile_id AND d.scope_id = t.scope_id AND r.id = d.current_revision_id AND r.released_at IS NOT NULL AND d.status = 'current' AND s.profile_id = d.profile_id AND s.revision_id = r.id AND LOWER(v.original_name) LIKE '%.docx' AND v.mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')");
    }

    private function guard(string $event, string $invalid): void
    {
        $name = 'template_publication_'.strtolower($event);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON document_template_publications WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Immutable template publication'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON document_template_publications FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable template publication'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_publications');
    }
};
