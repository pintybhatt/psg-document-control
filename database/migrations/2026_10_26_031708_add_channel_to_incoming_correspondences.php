<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_correspondences', function (Blueprint $table): void {
            $table->enum('channel', ['email', 'courier', 'hand_delivery', 'post', 'other'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incoming_correspondences', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
