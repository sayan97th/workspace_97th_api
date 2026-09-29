<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `webhook_token`: the secret part of the public URL a "When a webhook is received"
     *   automation listens on.
     * - `paused_at`/`paused_reason`: set when an automation was switched off by itself because a
     *   column, group, board, person or team it uses was deleted.
     */
    public function up(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->unique()->after('last_scheduled_run_at');
            $table->timestamp('paused_at')->nullable()->after('webhook_token');
            $table->string('paused_reason', 255)->nullable()->after('paused_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('board_automations', function (Blueprint $table) {
            $table->dropUnique(['webhook_token']);
            $table->dropColumn(['webhook_token', 'paused_at', 'paused_reason']);
        });
    }
};
