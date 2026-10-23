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
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('site_id');
            $table->string('code', 32);
            $table->string('name_en');
            $table->string('name_lo');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'code']);
            $table->index(['site_id', 'company_id']);
            $table->foreign(['site_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('sites')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
