<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_correspondences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('profile_id')->unique()->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('register_id');
            $table->foreignId('type_id');
            $table->foreign(['register_id', 'scope_id'])->references(['id', 'scope_id'])->on('register_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'scope_id'])->references(['id', 'scope_id'])->on('document_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'register_id'])->references(['type_id', 'register_id'])->on('document_type_register')->restrictOnDelete()->restrictOnUpdate();
            $table->string('signatory_name');
            $table->string('signatory_title')->nullable();
            $table->string('subject', 500);
            $table->text('description_en')->nullable();
            $table->text('description_lo')->nullable();
            $table->date('document_date');
            $table->enum('priority', ['normal', 'urgent']);
            $table->enum('status', ['draft', 'registered'])->default('draft');
            $table->string('internal_number', 191)->nullable()->unique();
            $table->string('official_number', 191)->nullable()->unique();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->index(['scope_id', 'id']);
        });
        Schema::create('outgoing_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('kind', ['primary', 'copy']);
            $table->string('organization');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('outgoing_registration_links', function (Blueprint $table): void {
            $table->foreignId('correspondence_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('purpose', ['internal_register', 'official_outgoing']);
            $table->foreignId('issuance_id')->unique()->constrained('issued_document_numbers')->restrictOnDelete()->restrictOnUpdate();
            $table->primary(['correspondence_id', 'purpose'], 'outgoing_registration_primary');
        });
        Schema::create('outgoing_correspondence_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('action', ['created', 'updated', 'registered']);
            $table->json('changed_fields');
            $table->timestamps();
        });
        foreach (['outgoing_correspondence_audits', 'outgoing_registration_links'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
        }
        $this->guard('outgoing_correspondence_audits', 'INSERT', 'EXISTS (SELECT 1 FROM outgoing_correspondence_audits WHERE id = NEW.id)');
        $this->guard('outgoing_registration_links', 'INSERT', 'EXISTS (SELECT 1 FROM outgoing_registration_links WHERE (correspondence_id = NEW.correspondence_id AND purpose = NEW.purpose) OR issuance_id = NEW.issuance_id)');
        $this->guard('outgoing_correspondences', 'INSERT', "NEW.status <> 'draft' OR EXISTS (SELECT 1 FROM outgoing_correspondences WHERE id = NEW.id OR profile_id = NEW.profile_id)");
        $this->guard('outgoing_correspondences', 'DELETE', '1 = 1');
        $this->guard('outgoing_correspondences', 'UPDATE', "OLD.status = 'registered' OR NEW.id <> OLD.id OR NEW.scope_id <> OLD.scope_id OR NEW.profile_id <> OLD.profile_id OR NEW.register_id <> OLD.register_id OR NEW.created_by <> OLD.created_by OR (NEW.status = 'registered' AND NOT EXISTS (SELECT 1 FROM outgoing_recipients r WHERE r.correspondence_id = OLD.id AND r.kind = 'primary'))");
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('outgoing_correspondences', $event, "NOT ((NEW.status = 'draft' AND NEW.internal_number IS NULL AND NEW.official_number IS NULL AND NEW.registered_at IS NULL AND NEW.registered_by IS NULL) OR (NEW.status = 'registered' AND NEW.internal_number IS NOT NULL AND NEW.official_number IS NOT NULL AND NEW.internal_number <> NEW.official_number AND NEW.registered_at IS NOT NULL AND NEW.registered_by IS NOT NULL)) OR NOT EXISTS (SELECT 1 FROM register_configurations r JOIN authorization_scopes s ON s.id = r.scope_id JOIN document_security_profiles p ON p.id = NEW.profile_id WHERE r.id = NEW.register_id AND r.scope_id = NEW.scope_id AND r.register_key = 'outgoing' AND s.scope_type = 'site' AND p.resource_type = 'outgoing' AND p.company_id = s.company_id AND p.site_id = s.site_id)");
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'DELETE' ? 'OLD' : 'NEW';
            $condition = "NOT EXISTS (SELECT 1 FROM outgoing_correspondences c WHERE c.id = {$row}.correspondence_id AND c.status = 'draft')";
            if ($event === 'UPDATE') {
                $condition .= ' OR NEW.id <> OLD.id OR NEW.correspondence_id <> OLD.correspondence_id';
            }
            if ($event === 'INSERT') {
                $condition .= ' OR EXISTS (SELECT 1 FROM outgoing_recipients WHERE id = NEW.id)';
            }
            $this->guard('outgoing_recipients', $event, $condition);
        }
        $this->guard('outgoing_registration_links', 'INSERT', "NOT EXISTS (SELECT 1 FROM outgoing_correspondences c JOIN issued_document_numbers i ON i.id = NEW.issuance_id JOIN numbering_series s ON s.id = i.series_id WHERE c.id = NEW.correspondence_id AND c.status = 'registered' AND ((NEW.purpose = 'internal_register' AND c.internal_number = i.number) OR (NEW.purpose = 'official_outgoing' AND c.official_number = i.number)) AND i.record_type = 'outgoing' AND i.record_key = CAST(c.id AS CHAR) AND s.purpose = NEW.purpose AND s.register_id = c.register_id AND s.scope_id = c.scope_id)");
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'outgoing_'.substr(md5($table.$event.$condition), 0, 24);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected outgoing correspondence'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected outgoing correspondence'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_correspondence_audits');
        Schema::dropIfExists('outgoing_registration_links');
        Schema::dropIfExists('outgoing_recipients');
        Schema::dropIfExists('outgoing_correspondences');
    }
};
