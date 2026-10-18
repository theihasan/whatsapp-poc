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
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->json('actions_taken')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('created_at');

            $table->index(['automation_rule_id', 'conversation_id', 'created_at'], 'automation_executions_rate_limit_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
