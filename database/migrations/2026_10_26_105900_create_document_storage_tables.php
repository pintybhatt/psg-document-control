<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('profile_id')->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('revision_id')->nullable()->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->index(['profile_id', 'revision_id']);
        });
        Schema::create('attachment_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('attachment_id')->constrained('document_attachments')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('version');
            $table->string('original_name', 200);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->text('reason');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['attachment_id', 'version']);
        });
        Schema::create('storage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('action', 60);
            $table->string('subject_id', 100)->nullable();
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'id']);
        });
        foreach (['document_attachments', 'attachment_versions', 'storage_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
        }
        $this->guard('document_attachments', 'INSERT', 'NOT EXISTS (SELECT 1 FROM incoming_correspondences WHERE profile_id = NEW.profile_id) AND NOT EXISTS (SELECT 1 FROM outgoing_correspondences WHERE profile_id = NEW.profile_id) AND NOT EXISTS (SELECT 1 FROM controlled_documents WHERE profile_id = NEW.profile_id)');
        $this->guard('document_attachments', 'INSERT', "(NEW.revision_id IS NULL AND EXISTS (SELECT 1 FROM controlled_documents WHERE profile_id = NEW.profile_id)) OR (NEW.revision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM controlled_revisions r JOIN controlled_documents d ON d.id = r.document_id WHERE r.id = NEW.revision_id AND d.profile_id = NEW.profile_id AND r.released_at IS NULL AND d.status IN ('draft', 'current')))");
        $this->guard('attachment_versions', 'INSERT', "NEW.version <> COALESCE((SELECT MAX(v.version) + 1 FROM attachment_versions v WHERE v.attachment_id = NEW.attachment_id), 1) OR EXISTS (SELECT 1 FROM document_attachments a JOIN controlled_revisions r ON r.id = a.revision_id JOIN controlled_documents d ON d.id = r.document_id WHERE a.id = NEW.attachment_id AND (r.released_at IS NOT NULL OR d.status NOT IN ('draft', 'current')))");
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'storage_'.substr(md5($table.$event.$condition), 0, 24);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected document storage'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected document storage'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_events');
        Schema::dropIfExists('attachment_versions');
        Schema::dropIfExists('document_attachments');
    }
};
