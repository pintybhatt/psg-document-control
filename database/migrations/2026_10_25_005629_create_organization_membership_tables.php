<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unique(['id', 'site_id', 'company_id'], 'departments_membership_parent_unique');
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        $unitKey = $sqlite
            ? "membership_type || ':' || CASE membership_type WHEN 'company' THEN company_id WHEN 'site' THEN site_id ELSE department_id END"
            : "CONCAT(membership_type, ':', CASE membership_type WHEN 'company' THEN company_id WHEN 'site' THEN site_id ELSE department_id END)";
        Schema::create('organization_memberships', function (Blueprint $table) use ($unitKey): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->enum('membership_type', ['company', 'site', 'department']);
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('site_id')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('revoked_at')->nullable();
            $table->string('unit_key', 64)->storedAs($unitKey);
            $table->string('current_key', 64)->nullable()->storedAs('CASE WHEN revoked_at IS NULL THEN unit_key ELSE NULL END');
            $table->timestamps();
            $table->unique(['user_id', 'current_key'], 'memberships_current_unique');
            $table->index(['site_id', 'company_id']);
            $table->index(['department_id', 'site_id', 'company_id'], 'memberships_department_index');
            $table->foreign(['site_id', 'company_id'])->references(['id', 'company_id'])->on('sites')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['department_id', 'site_id', 'company_id'], 'memberships_department_foreign')
                ->references(['id', 'site_id', 'company_id'])->on('departments')->restrictOnDelete()->restrictOnUpdate();
        });
        $shape = "((membership_type = 'company' AND site_id IS NULL AND department_id IS NULL)
            OR (membership_type = 'site' AND site_id IS NOT NULL AND department_id IS NULL)
            OR (membership_type = 'department' AND site_id IS NOT NULL AND department_id IS NOT NULL))
            AND ((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND active = 0))";
        if ($sqlite) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $newShape = preg_replace('/\b(membership_type|site_id|department_id|revoked_at|revoked_by|active)\b/', 'NEW.$1', $shape);
                DB::statement("CREATE TRIGGER memberships_shape_{$event} BEFORE {$event} ON organization_memberships
                    WHEN NOT ({$newShape}) BEGIN SELECT RAISE(ABORT, 'Invalid membership shape'); END");
            }
        } else {
            DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT memberships_shape CHECK ({$shape})");
        }
        Schema::create('membership_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('organization_memberships')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('action', 32);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_audits');
        Schema::dropIfExists('organization_memberships');
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_membership_parent_unique');
        });
    }
};
