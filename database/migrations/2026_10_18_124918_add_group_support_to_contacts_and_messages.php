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
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('wa_lid')->index();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender_jid')->nullable()->after('user_id');
            $table->string('sender_name')->nullable()->after('sender_jid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['sender_jid', 'sender_name']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['is_group']);
            $table->dropColumn('is_group');
        });
    }
};
