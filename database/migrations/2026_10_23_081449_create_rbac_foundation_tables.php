<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_scopes', function (Blueprint $table): void {
            $table->id();
            $table->enum('scope_type', ['global', 'company', 'site']);
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('site_id')->nullable();
            $table->string('scope_key', 64)->unique();
            $table->timestamps();
            $table->index(['site_id', 'company_id']);
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement("CREATE TRIGGER authorization_scopes_shape_{$event} BEFORE {$event} ON authorization_scopes
                    WHEN NEW.scope_key IS NOT (CASE
                        WHEN NEW.scope_type = 'global' AND NEW.company_id IS NULL AND NEW.site_id IS NULL THEN 'global'
                        WHEN NEW.scope_type = 'company' AND NEW.company_id IS NOT NULL AND NEW.site_id IS NULL THEN 'company:' || NEW.company_id
                        WHEN NEW.scope_type = 'site' AND NEW.company_id IS NOT NULL AND NEW.site_id IS NOT NULL THEN 'site:' || NEW.site_id
                        ELSE NULL END)
                    BEGIN SELECT RAISE(ABORT, 'Invalid authorization scope'); END");
            }
        } else {
            DB::statement("ALTER TABLE authorization_scopes ADD CONSTRAINT authorization_scopes_shape CHECK (
                (scope_type = 'global' AND company_id IS NULL AND site_id IS NULL AND scope_key = 'global') OR
                (scope_type = 'company' AND company_id IS NOT NULL AND site_id IS NULL AND scope_key = CONCAT('company:', company_id)) OR
                (scope_type = 'site' AND company_id IS NOT NULL AND site_id IS NOT NULL AND scope_key = CONCAT('site:', site_id)))");
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->string('code', 64)->unique();
            $table->string('name_en');
            $table->string('name_lo');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('area', 40);
            $table->string('name_en');
            $table->string('name_lo');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('role_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'scope_id'], 'user_role_scope_unique');
        });
        Schema::create('rbac_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 16);
            $table->string('action', 64);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_audits');
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('authorization_scopes');
    }
};
