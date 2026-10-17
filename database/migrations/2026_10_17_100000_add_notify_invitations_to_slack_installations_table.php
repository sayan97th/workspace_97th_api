<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Whether people invited to a workspace or a board also get the invitation as a Slack direct
     * message from the app's bot, on top of the email. On by default, like monday.com, an
     * administrator switches it off per Slack workspace.
     */
    public function up(): void
    {
        Schema::table('slack_installations', function (Blueprint $table) {
            $table->boolean('notify_invitations')->default(true)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_installations', function (Blueprint $table) {
            $table->dropColumn('notify_invitations');
        });
    }
};
