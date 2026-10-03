<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The account can keep several Slack workspaces connected and switch between them, like
     * monday.com's Connections page. Exactly one is active, it is the one notifications and
     * automations use. A member can be linked once per workspace, so switching back and forth
     * never forces anyone to connect their Slack account again.
     */
    public function up(): void
    {
        Schema::table('slack_installations', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('team_name');
            $table->string('team_url')->nullable()->after('team_name');
            $table->string('app_id')->nullable()->after('bot_user_id');
        });

        // Before this change the newest installation was the only one in use.
        $latest_id = DB::table('slack_installations')->max('id');
        if ($latest_id) {
            DB::table('slack_installations')->where('id', $latest_id)->update(['is_active' => true]);
        }

        // The composite index is added first, so `user_id` keeps an index for its foreign key.
        Schema::table('slack_user_links', function (Blueprint $table) {
            $table->unique(['user_id', 'slack_installation_id']);
        });

        Schema::table('slack_user_links', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only the links of the active workspace fit the old one link per user rule.
        $active_id = DB::table('slack_installations')->where('is_active', true)->value('id');
        DB::table('slack_installations')->where('id', '!=', $active_id ?? 0)->delete();

        Schema::table('slack_user_links', function (Blueprint $table) {
            $table->unique('user_id');
        });

        Schema::table('slack_user_links', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slack_installation_id']);
        });

        Schema::table('slack_installations', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'team_url', 'app_id']);
        });
    }
};
