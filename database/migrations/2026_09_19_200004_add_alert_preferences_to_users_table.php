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
        Schema::table('users', function (Blueprint $table) {
            // A short sound when a live notification arrives while the app is open.
            $table->boolean('notification_sound_enabled')->default(false);
            // Prefixes the browser tab title with the unread notification count.
            $table->boolean('tab_badge_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notification_sound_enabled', 'tab_badge_enabled']);
        });
    }
};
