<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflow_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('register_key', ['controlled', 'incoming', 'outgoing']);
            $table->unsignedInteger('version');
            $table->boolean('enabled')->default(false);
            $table->enum('sequence', ['sequential'])->default('sequential');
            $table->json('levels');
            $table->text('change_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['scope_id', 'register_key', 'version'], 'approval_workflow_version_unique');
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard($event, '1 = 1');
        }
        $this->guard('INSERT', "NEW.sequence != 'sequential' OR NEW.register_key NOT IN ('controlled', 'incoming', 'outgoing') OR NEW.enabled NOT IN (0, 1) OR NEW.version < 1 OR NOT EXISTS (SELECT 1 FROM authorization_scopes WHERE id = NEW.scope_id AND scope_type IN ('company', 'site')) OR EXISTS (SELECT 1 FROM approval_workflow_configurations WHERE id = NEW.id OR (scope_id = NEW.scope_id AND register_key = NEW.register_key AND version >= NEW.version)) OR NEW.version != (SELECT COALESCE(MAX(version), 0) + 1 FROM approval_workflow_configurations WHERE scope_id = NEW.scope_id AND register_key = NEW.register_key)");
    }

    private function guard(string $event, string $invalid): void
    {
        $name = 'approval_workflow_'.strtolower($event);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON approval_workflow_configurations WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Immutable approval workflow configuration'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON approval_workflow_configurations FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable approval workflow configuration'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_configurations');
    }
};
