<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Slack app id, known when the site created the app itself through a configuration
     * token. Lets the administration page link straight to that app's settings in Slack.
     */
    public function up(): void
    {
        Schema::table('slack_app_settings', function (Blueprint $table) {
            $table->string('app_id')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_app_settings', function (Blueprint $table) {
            $table->dropColumn('app_id');
        });
    }
};
