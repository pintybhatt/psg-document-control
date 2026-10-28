<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('review_enabled')->default(false);
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->string('review_baseline', 40)->nullable();
        });
        Schema::create('controlled_document_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('controlled_revisions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('document_version');
            $table->enum('outcome', ['no_changes_required', 'revision_required']);
            $table->text('comments')->nullable();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->string('baseline', 40)->nullable();
            $table->date('next_review_date')->nullable();
            $table->timestamps();
            $table->unique(['revision_id', 'document_version'], 'controlled_review_version_unique');
            $table->index(['revision_id', 'id']);
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard($event, '1 = 1');
        }
        $this->guard('INSERT', "NEW.outcome NOT IN ('no_changes_required', 'revision_required') OR NEW.document_version < 1 OR EXISTS (SELECT 1 FROM controlled_document_reviews WHERE id = NEW.id OR (revision_id = NEW.revision_id AND document_version = NEW.document_version)) OR NOT EXISTS (SELECT 1 FROM controlled_revisions r JOIN controlled_documents d ON d.id = r.document_id WHERE r.id = NEW.revision_id AND r.released_at IS NOT NULL AND d.status = 'current' AND d.current_revision_id = r.id AND d.lock_version = NEW.document_version)");
    }

    private function guard(string $event, string $invalid): void
    {
        $name = 'controlled_review_'.strtolower($event);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON controlled_document_reviews WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Immutable controlled review history'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON controlled_document_reviews FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable controlled review history'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_document_reviews');
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn(['review_enabled', 'review_interval_months', 'review_baseline']);
        });
    }
};
