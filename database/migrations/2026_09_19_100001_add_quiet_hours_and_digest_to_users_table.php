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
            // Do Not Disturb window: while it is active, notifications are still
            // stored in the bell, but no email, Slack message, toast or desktop
            // push is sent. Times are "HH:MM" wall-clock values in `timezone`.
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start', 5)->default('22:00');
            $table->string('quiet_hours_end', 5)->default('07:00');

            // "off" | "daily" | "weekly", see `App\Enums\EmailDigestFrequency`.
            $table->string('email_digest_frequency', 10)->default('off');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'email_digest_frequency']);
        });
    }
};
