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
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_dry_run')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('trigger', 32)->default('incoming_message');
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedSmallInteger('max_runs_per_conversation_per_hour')->default(1);
            $table->timestamps();

            $table->index(['is_enabled', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
