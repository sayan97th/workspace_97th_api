<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The production Slack app now comes from the API environment (SLACK_* variables) and the
     * saved row becomes the testing app. `label` names it on the administration page, for
     * example "Make It Simple", and `is_active` is whether the site uses it instead of the
     * production app.
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
