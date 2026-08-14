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
            $table->string('working_status')->nullable()->after('timezone');
            $table->string('working_status_dates')->nullable()->after('working_status');
            $table->boolean('disable_notifications_while_away')->default(false)->after('working_status_dates');
            $table->boolean('hide_online_status')->default(false)->after('disable_notifications_while_away');

            $table->json('notification_preferences')->nullable()->after('hide_online_status');
            $table->boolean('desktop_notifications_enabled')->default(false)->after('notification_preferences');

            $table->string('language')->default('en')->after('desktop_notifications_enabled');
            $table->string('time_format')->default('12')->after('language');
            $table->string('date_format')->default('long')->after('time_format');
            $table->string('first_day_of_week')->default('sunday')->after('date_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'working_status',
                'working_status_dates',
                'disable_notifications_while_away',
                'hide_online_status',
                'notification_preferences',
                'desktop_notifications_enabled',
                'language',
                'time_format',
                'date_format',
                'first_day_of_week',
            ]);
        });
    }
};
