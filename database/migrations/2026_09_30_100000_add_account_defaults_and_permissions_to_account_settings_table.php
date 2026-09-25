<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account wide defaults applied to every new user (timezone, language, date and time
     * format, first day of the week), plus the per role account permissions matrix.
     */
    public function up(): void
    {
        Schema::table('account_settings', function (Blueprint $table) {
            $table->string('default_timezone')->nullable()->after('home_page');
            $table->string('default_language', 5)->default('en')->after('default_timezone');
            $table->string('default_date_format', 10)->default('long')->after('default_language');
            $table->string('default_time_format', 5)->default('12')->after('default_date_format');
            $table->string('default_first_day_of_week', 10)->default('sunday')->after('default_time_format');
            $table->json('account_permissions')->nullable()->after('default_first_day_of_week');
        });
    }

    public function down(): void
    {
        Schema::table('account_settings', function (Blueprint $table) {
            $table->dropColumn([
                'default_timezone',
                'default_language',
                'default_date_format',
                'default_time_format',
                'default_first_day_of_week',
                'account_permissions',
            ]);
        });
    }
};
