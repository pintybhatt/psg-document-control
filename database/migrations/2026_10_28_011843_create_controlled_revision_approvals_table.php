<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('controlled_revision_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('decision', ['submitted', 'approved', 'rejected']);
            $table->unsignedInteger('document_version');
            $table->text('comments')->nullable();
            $table->json('snapshot');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['revision_id', 'document_version'], 'controlled_approval_version_unique');
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard($event, '1 = 1');
        }
        $this->guard('INSERT', "NEW.decision NOT IN ('submitted', 'approved', 'rejected') OR NEW.document_version < 1 OR EXISTS (SELECT 1 FROM controlled_revision_approvals WHERE id = NEW.id OR (revision_id = NEW.revision_id AND document_version = NEW.document_version)) OR NOT EXISTS (SELECT 1 FROM controlled_revisions r JOIN controlled_documents d ON d.id = r.document_id WHERE r.id = NEW.revision_id AND r.released_at IS NULL AND d.status IN ('draft', 'current') AND d.lock_version = NEW.document_version)");
    }

    private function guard(string $event, string $invalid): void
    {
        $name = 'controlled_approval_'.strtolower($event);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON controlled_revision_approvals WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Immutable controlled approval history'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON controlled_revision_approvals FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable controlled approval history'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('controlled_revision_approvals');
    }
};
