<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account's monthly automation action quota: the limit on `account_settings` (null means
     * unlimited) and one counter row per calendar month, with the highest usage warning already
     * sent that month (0, 80 or 100 percent).
     */
    public function up(): void
    {
        Schema::table('account_settings', function (Blueprint $table) {
            $table->unsignedInteger('automation_monthly_action_limit')->nullable();
        });

        Schema::create('automation_usage_months', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->unsignedInteger('action_count')->default(0);
            $table->unsignedTinyInteger('warned_percent')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_usage_months');

        Schema::table('account_settings', function (Blueprint $table) {
            $table->dropColumn('automation_monthly_action_limit');
        });
    }
};
