<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_security_profiles', function (Blueprint $table): void {
            $table->id();
            $table->enum('resource_type', ['incoming', 'outgoing', 'controlled']);
            $table->string('resource_key', 64);
            $this->ownership($table, 'profiles');
            $table->enum('classification', ['normal', 'confidential', 'highly_confidential']);
            $table->boolean('restricted')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_key'], 'security_resource_unique');
        });
        $this->check('document_security_profiles', 'profile_restricted', "classification != 'highly_confidential' OR restricted = 1");

        $sqlite = DB::getDriverName() === 'sqlite';
        $clearanceKey = $sqlite
            ? "classification || ':' || CASE WHEN department_id IS NULL THEN 'site:' || site_id ELSE 'department:' || department_id END"
            : "CONCAT(classification, ':', CASE WHEN department_id IS NULL THEN CONCAT('site:', site_id) ELSE CONCAT('department:', department_id) END)";
        Schema::create('confidentiality_clearances', function (Blueprint $table) use ($clearanceKey): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $this->ownership($table, 'clearances');
            $table->enum('classification', ['confidential', 'highly_confidential']);
            $this->lifecycle($table);
            $table->string('current_key', 100)->nullable()->storedAs("CASE WHEN revoked_at IS NULL THEN {$clearanceKey} ELSE NULL END");
            $table->unique(['user_id', 'current_key'], 'security_clearance_current_unique');
        });
        $this->check('confidentiality_clearances', 'clearance_revocation', '(revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND active = 0)');
        Schema::create('document_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->enum('operation', ['metadata', 'preview', 'download']);
            $this->lifecycle($table);
            $table->string('current_operation', 16)->nullable()->storedAs('CASE WHEN revoked_at IS NULL THEN operation ELSE NULL END');
            $table->unique(['profile_id', 'user_id', 'current_operation'], 'security_document_grant_unique');
        });
        $this->check('document_access_grants', 'document_grant_revocation', '(revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND active = 0)');
        Schema::create('document_security_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('profile_id')->nullable()->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('clearance_id')->nullable()->constrained('confidentiality_clearances')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('grant_id')->nullable()->constrained('document_access_grants')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('event', ['access.allowed', 'access.denied', 'profile.registered', 'profile.restricted', 'clearance.granted', 'clearance.updated', 'clearance.revoked', 'grant.granted', 'grant.updated', 'grant.revoked']);
            $table->enum('operation', ['metadata', 'preview', 'download'])->nullable();
            $table->string('reason', 32)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $statement = $sqlite
                ? "CREATE TRIGGER security_audit_{$event} BEFORE {$event} ON document_security_audits BEGIN SELECT RAISE(ABORT, 'Immutable security audit'); END"
                : "CREATE TRIGGER security_audit_{$event} BEFORE {$event} ON document_security_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable security audit'";
            DB::statement($statement);
        }
    }

    private function ownership(Blueprint $table, string $prefix): void
    {
        $table->foreignId('company_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
        $table->foreignId('site_id');
        $table->foreignId('department_id')->nullable();
        $table->index(['site_id', 'company_id'], 'security_'.$prefix.'_site_index');
        $table->foreign(['site_id', 'company_id'], 'security_'.$prefix.'_site_foreign')->references(['id', 'company_id'])->on('sites')->restrictOnDelete()->restrictOnUpdate();
        $table->index(['department_id', 'site_id', 'company_id'], 'security_'.$prefix.'_department_index');
        $table->foreign(['department_id', 'site_id', 'company_id'], 'security_'.$prefix.'_department_foreign')
            ->references(['id', 'site_id', 'company_id'])->on('departments')->restrictOnDelete()->restrictOnUpdate();
    }

    private function lifecycle(Blueprint $table): void
    {
        $table->boolean('active')->default(true);
        $table->timestamp('expires_at')->nullable();
        $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $table->timestamp('revoked_at')->nullable();
        $table->timestamps();
    }

    private function check(string $table, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $newCondition = preg_replace('/\b(classification|restricted|revoked_at|revoked_by|active)\b/', 'NEW.$1', $condition);
                DB::statement("CREATE TRIGGER security_{$name}_{$event} BEFORE {$event} ON {$table} WHEN NOT ({$newCondition}) BEGIN SELECT RAISE(ABORT, 'Invalid security state'); END");
            }
        } else {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT security_{$name}_check CHECK ({$condition})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_security_audits');
        Schema::dropIfExists('document_access_grants');
        Schema::dropIfExists('confidentiality_clearances');
        Schema::dropIfExists('document_security_profiles');
    }
};
