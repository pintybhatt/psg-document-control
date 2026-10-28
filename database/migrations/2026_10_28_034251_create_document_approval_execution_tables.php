<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_approval_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('revision_id')->nullable()->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('configuration_id')->constrained('approval_workflow_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->char('content_hash', 64);
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['profile_id', 'revision_id', 'id'], 'approval_submission_target');
        });
        Schema::create('correspondence_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('document_approval_submissions')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedTinyInteger('level');
            $table->enum('decision', ['submitted', 'approved', 'rejected']);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('comments')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['submission_id', 'level']);
        });
        foreach (['document_approval_submissions', 'correspondence_approvals'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
            $this->guard($table, 'INSERT', "EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id)");
        }
        $this->guard('document_approval_submissions', 'INSERT', "NOT EXISTS (SELECT 1 FROM document_security_profiles p JOIN approval_workflow_configurations c ON c.id = NEW.configuration_id JOIN authorization_scopes s ON s.id = c.scope_id WHERE p.id = NEW.profile_id AND p.resource_type = c.register_key AND ((s.scope_type = 'site' AND s.site_id = p.site_id AND s.company_id = p.company_id) OR (s.scope_type = 'company' AND s.company_id = p.company_id)) AND c.enabled = 1 AND ((p.resource_type != 'controlled' AND NEW.revision_id IS NULL) OR (p.resource_type = 'controlled' AND EXISTS (SELECT 1 FROM controlled_revisions r JOIN controlled_documents d ON d.id = r.document_id WHERE r.id = NEW.revision_id AND d.profile_id = p.id AND r.released_at IS NULL))))", 'target');
        $this->guard('correspondence_approvals', 'INSERT', "NEW.level > 3 OR (NEW.level = 0 AND NEW.decision != 'submitted') OR (NEW.level > 0 AND NEW.decision NOT IN ('approved', 'rejected')) OR EXISTS (SELECT 1 FROM correspondence_approvals WHERE submission_id = NEW.submission_id AND (level >= NEW.level OR decision = 'rejected')) OR (NEW.level > 0 AND NEW.level != (SELECT COALESCE(MAX(level), -1) + 1 FROM correspondence_approvals WHERE submission_id = NEW.submission_id)) OR NOT EXISTS (SELECT 1 FROM document_approval_submissions s JOIN document_security_profiles p ON p.id = s.profile_id WHERE s.id = NEW.submission_id AND p.resource_type IN ('incoming', 'outgoing') AND (NEW.level = 0 OR s.submitted_by != NEW.actor_id))", 'sequence');
        $cast = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'UNSIGNED';
        $submission = "CAST(json_extract(NEW.snapshot, '$.workflow.submission_id') AS {$cast})";
        $level = "CAST(json_extract(NEW.snapshot, '$.workflow.level') AS {$cast})";
        $existingSubmission = "CAST(json_extract(snapshot, '$.workflow.submission_id') AS {$cast})";
        $existingLevel = "CAST(json_extract(snapshot, '$.workflow.level') AS {$cast})";
        $this->guard('controlled_revision_approvals', 'INSERT', "{$submission} IS NOT NULL AND (NOT EXISTS (SELECT 1 FROM document_approval_submissions s WHERE s.id = {$submission} AND s.revision_id = NEW.revision_id AND (NEW.decision = 'submitted' OR s.submitted_by != NEW.actor_id)) OR {$level} NOT BETWEEN 0 AND 3 OR ({$level} = 0 AND NEW.decision != 'submitted') OR ({$level} > 0 AND NEW.decision = 'submitted') OR EXISTS (SELECT 1 FROM controlled_revision_approvals WHERE {$existingSubmission} = {$submission} AND ({$existingLevel} >= {$level} OR decision = 'rejected')) OR ({$level} > 0 AND {$level} != (SELECT COALESCE(MAX({$existingLevel}), -1) + 1 FROM controlled_revision_approvals WHERE {$existingSubmission} = {$submission})))", 'workflow');
    }

    private function guard(string $table, string $event, string $invalid, string $suffix = ''): void
    {
        $name = $table.'_'.strtolower($event).$suffix;
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Protected approval execution history'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected approval execution history'; END IF; END");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS controlled_revision_approvals_insertworkflow');
        Schema::dropIfExists('correspondence_approvals');
        Schema::dropIfExists('document_approval_submissions');
    }
};
