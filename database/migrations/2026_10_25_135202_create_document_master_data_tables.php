<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table): void {
            $this->masterColumns($table);
            $this->namedColumns($table);
            $table->unique(['scope_id', 'code'], 'category_scope_code_unique');
            $table->unique(['id', 'scope_id'], 'category_scope_parent_unique');
        });
        Schema::create('register_configurations', function (Blueprint $table): void {
            $this->masterColumns($table);
            $table->enum('register_key', ['incoming', 'outgoing', 'controlled']);
            $table->unique(['scope_id', 'register_key'], 'register_scope_key_unique');
            $table->unique(['id', 'scope_id'], 'register_scope_parent_unique');
        });
        Schema::create('document_types', function (Blueprint $table): void {
            $this->masterColumns($table);
            $this->namedColumns($table);
            $table->foreignId('category_id');
            $table->unique(['scope_id', 'code'], 'type_scope_code_unique');
            $table->unique(['id', 'scope_id'], 'type_scope_parent_unique');
            $table->index(['category_id', 'scope_id'], 'type_category_scope_index');
            $table->foreign(['category_id', 'scope_id'], 'type_category_scope_foreign')->references(['id', 'scope_id'])->on('document_categories')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('document_type_register', function (Blueprint $table): void {
            $table->foreignId('type_id');
            $table->foreignId('register_id');
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->primary(['type_id', 'register_id']);
            $table->index(['type_id', 'scope_id'], 'type_register_type_index');
            $table->index(['register_id', 'scope_id'], 'type_register_register_index');
            $table->foreign(['type_id', 'scope_id'], 'type_register_type_foreign')->references(['id', 'scope_id'])->on('document_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['register_id', 'scope_id'], 'type_register_register_foreign')->references(['id', 'scope_id'])->on('register_configurations')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('master_data_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('subject_type', ['categories', 'types', 'registers']);
            $table->unsignedBigInteger('subject_id');
            $table->enum('action', ['created', 'updated']);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamps();
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::statement(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER master_audit_{$event} BEFORE {$event} ON master_data_audits BEGIN SELECT RAISE(ABORT, 'Immutable master audit'); END"
                : "CREATE TRIGGER master_audit_{$event} BEFORE {$event} ON master_data_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable master audit'");
        }
    }

    private function masterColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
        $table->text('description_en')->nullable();
        $table->text('description_lo')->nullable();
        $table->boolean('active')->default(true);
        $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $table->timestamps();
    }

    private function namedColumns(Blueprint $table): void
    {
        $table->string('code', 64);
        $table->string('name_en');
        $table->string('name_lo');
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_audits');
        Schema::dropIfExists('document_type_register');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('register_configurations');
        Schema::dropIfExists('document_categories');
    }
};
