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
        Schema::create('xppl_correspondence_policies', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('register_kind', 16);
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('activated_at');
            $table->primary(['company_id', 'register_kind']);
        });
        foreach (['UPDATE', 'DELETE', 'INSERT'] as $event) {
            $condition = $event === 'INSERT'
                ? "NEW.register_kind NOT IN ('incoming', 'outgoing') OR EXISTS (SELECT 1 FROM xppl_correspondence_policies WHERE company_id = NEW.company_id AND register_kind = NEW.register_kind)"
                : '1 = 1';
            $this->guard('xppl_correspondence_policy_'.strtolower($event), 'xppl_correspondence_policies', $event, $condition);
        }
        $this->counterGuard(true);
        DB::unprepared('DROP TRIGGER xppl_'.substr(md5('xppl_numbering_countersINSERT'), 0, 24));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('xppl_correspondence_policies')->exists()
            || DB::table('xppl_numbering_namespaces')->whereIn('format', ['xppl_incoming', 'xppl_outgoing'])->exists()) {
            throw new LogicException('Correspondence policy or numbering history requires a forward-only repair.');
        }
        $this->counterGuard(false);
        DB::unprepared('DROP TRIGGER xppl_counter_correspondence_insert');
        Schema::dropIfExists('xppl_correspondence_policies');
    }

    private function counterGuard(bool $extended): void
    {
        $formats = $extended ? "'xppl_letter', 'xppl_incoming', 'xppl_outgoing'" : "'xppl_letter'";
        $condition = "NEW.value != NEW.baseline OR NEW.baseline < 0 OR NEW.value > 999999999999 OR NOT EXISTS (SELECT 1 FROM xppl_numbering_namespaces WHERE id = NEW.namespace_id AND ((format = 'xppl_controlled' AND NEW.period = 0) OR (format IN ($formats) AND NEW.period BETWEEN 2000 AND 9999))) OR EXISTS (SELECT 1 FROM xppl_numbering_counters WHERE namespace_id = NEW.namespace_id AND period = NEW.period)";
        $name = $extended ? 'xppl_counter_correspondence_insert' : 'xppl_'.substr(md5('xppl_numbering_countersINSERT'), 0, 24);
        $this->guard($name, 'xppl_numbering_counters', 'INSERT', $condition);
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Protected XPPL numbering state'); END"
            : "CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected XPPL numbering state'; END IF; END");
    }
};
