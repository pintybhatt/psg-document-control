<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_recipients', function (Blueprint $table): void {
            $table->unique(['id', 'correspondence_id'], 'outgoing_recipient_owner_unique');
        });
        Schema::create('outgoing_operations', function (Blueprint $table): void {
            $table->foreignId('correspondence_id')->primary()->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('status', ['registered', 'dispatched', 'delivered', 'closed'])->default('registered');
            $table->date('dispatch_date')->nullable();
            $table->enum('dispatch_channel', ['email', 'courier', 'hand_delivery', 'post', 'other'])->nullable();
            $table->string('dispatch_reference')->nullable();
            $table->text('dispatch_remarks')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('closure_remarks')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('outgoing_deliveries', function (Blueprint $table): void {
            $table->foreignId('recipient_id')->primary();
            $table->foreignId('correspondence_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['recipient_id', 'correspondence_id'], 'outgoing_delivery_owner_fk')->references(['id', 'correspondence_id'])->on('outgoing_recipients')->restrictOnDelete()->restrictOnUpdate();
            $table->date('delivery_date')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->date('acknowledgment_date')->nullable();
            $table->string('acknowledgment_reference')->nullable();
            $table->timestamps();
        });
        Schema::create('outgoing_operation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('revision');
            $table->enum('action', ['dispatch', 'correct_dispatch', 'delivery', 'close', 'reopen', 'note', 'link', 'unlink']);
            $table->text('note')->nullable();
            $table->json('before');
            $table->json('after');
            $table->timestamps();
            $table->unique(['correspondence_id', 'revision'], 'outgoing_operation_revision_unique');
        });
        Schema::create('outgoing_incoming_links', function (Blueprint $table): void {
            $table->foreignId('outgoing_id')->constrained('outgoing_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('incoming_id')->constrained('incoming_correspondences')->restrictOnDelete()->restrictOnUpdate();
            $table->primary(['outgoing_id', 'incoming_id']);
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('outgoing_operation_audits', $event, '1 = 1');
        }
        $this->guard('outgoing_operation_audits', 'INSERT', 'EXISTS (SELECT 1 FROM outgoing_operation_audits WHERE id = NEW.id OR (correspondence_id = NEW.correspondence_id AND revision = NEW.revision))');
        $this->guard('outgoing_operations', 'DELETE', '1 = 1');
        $this->guard('outgoing_operations', 'INSERT', 'EXISTS (SELECT 1 FROM outgoing_operations WHERE correspondence_id = NEW.correspondence_id)');
        $this->guard('outgoing_operations', 'UPDATE', 'NEW.correspondence_id <> OLD.correspondence_id OR NEW.revision <> OLD.revision + 1');
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('outgoing_operations', $event, "NOT EXISTS (SELECT 1 FROM outgoing_correspondences c WHERE c.id = NEW.correspondence_id AND c.status = 'registered') OR NOT ((NEW.dispatch_date IS NULL AND NEW.dispatch_channel IS NULL AND NEW.dispatch_reference IS NULL AND NEW.dispatch_remarks IS NULL AND NEW.status IN ('registered', 'closed')) OR (NEW.dispatch_date IS NOT NULL AND NEW.dispatch_channel IS NOT NULL AND NEW.dispatch_channel IN ('email', 'courier', 'hand_delivery', 'post', 'other') AND NEW.status IN ('dispatched', 'delivered', 'closed'))) OR NOT ((NEW.status = 'closed' AND NEW.closed_at IS NOT NULL AND NEW.closed_by IS NOT NULL) OR (NEW.status <> 'closed' AND NEW.closed_at IS NULL AND NEW.closed_by IS NULL AND NEW.closure_remarks IS NULL)) OR (NEW.status = 'delivered' AND EXISTS (SELECT 1 FROM outgoing_recipients r WHERE r.correspondence_id = NEW.correspondence_id AND r.kind = 'primary' AND NOT EXISTS (SELECT 1 FROM outgoing_deliveries d WHERE d.recipient_id = r.id AND d.delivery_date IS NOT NULL))) OR EXISTS (SELECT 1 FROM outgoing_deliveries d WHERE d.correspondence_id = NEW.correspondence_id AND d.delivery_date IS NOT NULL AND (NEW.dispatch_date IS NULL OR d.delivery_date < NEW.dispatch_date))");
        }
        $this->guard('outgoing_deliveries', 'DELETE', '1 = 1');
        $this->guard('outgoing_deliveries', 'INSERT', 'EXISTS (SELECT 1 FROM outgoing_deliveries WHERE recipient_id = NEW.recipient_id)');
        $this->guard('outgoing_deliveries', 'UPDATE', 'NEW.recipient_id <> OLD.recipient_id OR NEW.correspondence_id <> OLD.correspondence_id');
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('outgoing_deliveries', $event, "NOT EXISTS (SELECT 1 FROM outgoing_operations o WHERE o.correspondence_id = NEW.correspondence_id AND o.status IN ('dispatched', 'delivered') AND o.dispatch_date IS NOT NULL AND (NEW.delivery_date IS NULL OR NEW.delivery_date >= o.dispatch_date)) OR (NEW.delivery_date IS NULL AND (NEW.acknowledged_by IS NOT NULL OR NEW.acknowledgment_date IS NOT NULL OR NEW.acknowledgment_reference IS NOT NULL)) OR (NEW.acknowledgment_date IS NOT NULL AND NEW.acknowledgment_date < NEW.delivery_date)");
        }
        $this->guard('outgoing_incoming_links', 'UPDATE', '1 = 1');
        $this->guard('outgoing_incoming_links', 'INSERT', "NOT EXISTS (SELECT 1 FROM outgoing_correspondences o JOIN incoming_correspondences i ON i.id = NEW.incoming_id JOIN document_security_profiles p ON p.id = o.profile_id JOIN document_security_profiles q ON q.id = i.profile_id WHERE o.id = NEW.outgoing_id AND o.status = 'registered' AND i.status = 'registered' AND o.scope_id = i.scope_id AND (p.department_id = q.department_id OR (p.department_id IS NULL AND q.department_id IS NULL)))");
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'outgoing_dispatch_'.substr(md5($table.$event.$condition), 0, 20);
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected outgoing operations'); END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected outgoing operations'; END IF; END");
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_incoming_links');
        Schema::dropIfExists('outgoing_operation_audits');
        Schema::dropIfExists('outgoing_deliveries');
        Schema::dropIfExists('outgoing_operations');
        Schema::table('outgoing_recipients', function (Blueprint $table): void {
            $table->dropUnique('outgoing_recipient_owner_unique');
        });
    }
};
