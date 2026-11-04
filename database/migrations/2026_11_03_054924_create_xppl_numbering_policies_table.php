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
        Schema::create('xppl_numbering_policies', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('activated_at');
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'xppl_policy_'.strtolower($event);
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER $name BEFORE $event ON xppl_numbering_policies BEGIN SELECT RAISE(ABORT, 'Immutable XPPL activation'); END"
                : "CREATE TRIGGER $name BEFORE $event ON xppl_numbering_policies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable XPPL activation'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('xppl_numbering_policies')->exists()) {
            throw new LogicException('Cannot remove an activated XPPL numbering policy.');
        }
        Schema::dropIfExists('xppl_numbering_policies');
    }
};
