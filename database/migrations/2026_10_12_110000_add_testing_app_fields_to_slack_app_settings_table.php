<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The 97th Floor Slack app now comes from the API environment (SLACK_* variables) and any
     * number of other apps can be saved next to it. `label` names a saved app on the
     * administration page, for example "Make It Simple", and `is_active` marks the selected one.
     */
    public function up(): void
    {
        Schema::table('slack_app_settings', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('app_id');
            $table->boolean('is_active')->default(false)->after('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_app_settings', function (Blueprint $table) {
            $table->dropColumn(['label', 'is_active']);
        });
    }
};
