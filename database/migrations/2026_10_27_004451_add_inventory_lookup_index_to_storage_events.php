<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('storage_events', function (Blueprint $table) {
            $table->index(['subject_id', 'action', 'id'], 'storage_events_inventory_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storage_events', function (Blueprint $table) {
            $table->dropIndex('storage_events_inventory_lookup');
        });
    }
};
