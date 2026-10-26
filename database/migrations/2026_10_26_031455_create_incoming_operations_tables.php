<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_correspondence_operations', function (Blueprint $table): void {
            $table->foreignId('correspondence_id')->primary()->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('status', ['received', 'assigned', 'in_progress', 'responded', 'closed'])->default('received');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('responsible_department_id')->nullable()->constrained('departments', indexName: 'incoming_ops_department_fk')->restrictOnDelete()->restrictOnUpdate();
            $table->date('due_date')->nullable();
            $table->boolean('response_required')->default(false);
            $table->timestamp('response_completed_at')->nullable();
            $table->foreignId('response_completed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('closure_remarks')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('incoming_operation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('revision');
            $table->enum('action', ['updated', 'noted', 'linked', 'unlinked', 'closed', 'reopened']);
            $table->text('note')->nullable();
            $table->json('before');
            $table->json('after');
            $table->timestamps();
            $table->unique(['correspondence_id', 'revision'], 'incoming_operation_revision_unique');
        });
        Schema::create('incoming_correspondence_relations', function (Blueprint $table): void {
            $table->foreignId('correspondence_id')->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('related_id')->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->primary(['correspondence_id', 'related_id'], 'incoming_relation_primary');
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('incoming_operation_audits', $event, '1 = 1');
        }
        $this->guard('incoming_operation_audits', 'INSERT', 'EXISTS (SELECT 1 FROM incoming_operation_audits WHERE id = NEW.id OR (correspondence_id = NEW.correspondence_id AND revision = NEW.revision))');
        $this->guard('incoming_correspondence_operations', 'DELETE', '1 = 1');
        $this->guard('incoming_correspondence_operations', 'INSERT', 'EXISTS (SELECT 1 FROM incoming_correspondence_operations WHERE correspondence_id = NEW.correspondence_id)');
        $this->guard('incoming_correspondence_operations', 'UPDATE', 'NEW.correspondence_id <> OLD.correspondence_id OR NEW.revision <> OLD.revision + 1');
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('incoming_correspondence_operations', $event, "NOT EXISTS (SELECT 1 FROM incoming_correspondences c JOIN document_security_profiles p ON p.id = c.profile_id WHERE c.id = NEW.correspondence_id AND c.status = 'registered' AND (NEW.responsible_department_id IS NULL OR EXISTS (SELECT 1 FROM departments d WHERE d.id = NEW.responsible_department_id AND d.company_id = p.company_id AND d.site_id = p.site_id))) OR NOT ((NEW.status = 'closed' AND NEW.closed_at IS NOT NULL AND NEW.closed_by IS NOT NULL) OR (NEW.status IN ('received', 'assigned', 'in_progress', 'responded') AND NEW.closed_at IS NULL AND NEW.closed_by IS NULL AND NEW.closure_remarks IS NULL)) OR ((NEW.response_completed_at IS NULL AND NEW.response_completed_by IS NOT NULL) OR (NEW.response_completed_at IS NOT NULL AND (NEW.response_completed_by IS NULL OR NEW.response_required = 0))) OR (NEW.status IN ('closed', 'responded') AND NEW.response_required = 1 AND NEW.response_completed_at IS NULL) OR (NEW.status = 'responded' AND NEW.response_completed_at IS NULL) OR (NEW.status = 'assigned' AND NEW.responsible_user_id IS NULL AND NEW.responsible_department_id IS NULL)");
        }
        $this->guard('incoming_correspondence_relations', 'UPDATE', '1 = 1');
        $this->guard('incoming_correspondence_relations', 'INSERT', "NEW.correspondence_id >= NEW.related_id OR NOT EXISTS (SELECT 1 FROM incoming_correspondences a JOIN incoming_correspondences b ON b.id = NEW.related_id JOIN document_security_profiles p ON p.id = a.profile_id JOIN document_security_profiles q ON q.id = b.profile_id WHERE a.id = NEW.correspondence_id AND a.status = 'registered' AND b.status = 'registered' AND a.scope_id = b.scope_id AND (p.department_id = q.department_id OR (p.department_id IS NULL AND q.department_id IS NULL)))");
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'incoming_ops_'.substr(md5($table.$event.$condition), 0, 20);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected incoming operations'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected incoming operations'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_correspondence_relations');
        Schema::dropIfExists('incoming_operation_audits');
        Schema::dropIfExists('incoming_correspondence_operations');
    }
};
