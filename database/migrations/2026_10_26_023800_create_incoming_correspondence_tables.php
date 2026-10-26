<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_correspondences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_id')->constrained('authorization_scopes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('profile_id')->unique()->constrained('document_security_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('register_id');
            $table->foreignId('type_id');
            $table->foreign(['register_id', 'scope_id'])->references(['id', 'scope_id'])->on('register_configurations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'scope_id'])->references(['id', 'scope_id'])->on('document_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['type_id', 'register_id'])->references(['type_id', 'register_id'])->on('document_type_register')->restrictOnDelete()->restrictOnUpdate();
            $table->string('sender_organization');
            $table->string('sender_contact')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('sender_phone', 64)->nullable();
            $table->string('external_reference')->nullable();
            $table->date('correspondence_date');
            $table->date('received_date');
            $table->string('subject', 500);
            $table->text('description_en')->nullable();
            $table->text('description_lo')->nullable();
            $table->enum('priority', ['normal', 'urgent']);
            $table->enum('status', ['draft', 'registered'])->default('draft');
            $table->string('internal_number', 191)->nullable()->unique();
            $table->timestamp('registered_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->index(['scope_id', 'id']);
        });
        Schema::create('incoming_registration_links', function (Blueprint $table): void {
            $table->foreignId('correspondence_id')->primary()->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('issuance_id')->unique()->constrained('issued_document_numbers')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('incoming_correspondence_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('action', ['created', 'updated', 'registered']);
            $table->json('changed_fields');
            $table->timestamps();
        });
        foreach (['incoming_correspondence_audits', 'incoming_registration_links'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, '1 = 1');
            }
        }
        $this->guard('incoming_correspondence_audits', 'INSERT', 'EXISTS (SELECT 1 FROM incoming_correspondence_audits WHERE id = NEW.id)');
        $this->guard('incoming_registration_links', 'INSERT', 'EXISTS (SELECT 1 FROM incoming_registration_links WHERE correspondence_id = NEW.correspondence_id OR issuance_id = NEW.issuance_id)');
        $this->guard('incoming_correspondences', 'INSERT', 'EXISTS (SELECT 1 FROM incoming_correspondences WHERE id = NEW.id OR profile_id = NEW.profile_id OR (NEW.internal_number IS NOT NULL AND internal_number = NEW.internal_number))');
        $this->guard('incoming_correspondences', 'DELETE', '1 = 1');
        $this->guard('incoming_correspondences', 'UPDATE', "OLD.status = 'registered' OR NEW.scope_id <> OLD.scope_id OR NEW.profile_id <> OLD.profile_id OR NEW.register_id <> OLD.register_id OR NEW.created_by <> OLD.created_by");
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('incoming_correspondences', $event, "NOT ((NEW.status = 'draft' AND NEW.internal_number IS NULL AND NEW.registered_at IS NULL AND NEW.registered_by IS NULL) OR (NEW.status = 'registered' AND NEW.internal_number IS NOT NULL AND NEW.registered_at IS NOT NULL AND NEW.registered_by IS NOT NULL)) OR NOT EXISTS (SELECT 1 FROM register_configurations r JOIN authorization_scopes s ON s.id = r.scope_id JOIN document_security_profiles p ON p.id = NEW.profile_id WHERE r.id = NEW.register_id AND r.scope_id = NEW.scope_id AND r.register_key = 'incoming' AND s.scope_type = 'site' AND p.resource_type = 'incoming' AND p.company_id = s.company_id AND p.site_id = s.site_id)");
        }
        $this->guard('incoming_registration_links', 'INSERT', "NOT EXISTS (SELECT 1 FROM incoming_correspondences c JOIN issued_document_numbers i ON i.id = NEW.issuance_id JOIN numbering_series s ON s.id = i.series_id WHERE c.id = NEW.correspondence_id AND c.status = 'registered' AND c.internal_number = i.number AND i.record_type = 'incoming' AND i.record_key = CAST(c.id AS CHAR) AND s.purpose = 'internal_register' AND s.register_id = c.register_id AND s.scope_id = c.scope_id)");
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = substr($table, 0, 35).'_'.$event.'_'.substr(md5($condition), 0, 6);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected incoming correspondence'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected incoming correspondence'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_correspondence_audits');
        Schema::dropIfExists('incoming_registration_links');
        Schema::dropIfExists('incoming_correspondences');
    }
};
