<?php

use App\Models\SlackAppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Slack app credentials now live only in Administration > Integrations. A deployment
     * that still had them in the environment gets them copied over once, so the connection keeps
     * working, after which the SLACK_* variables can be deleted. Run before removing them.
     */
    public function up(): void
    {
        $client_id = env('SLACK_CLIENT_ID');
        $client_secret = env('SLACK_CLIENT_SECRET');

        if (SlackAppSetting::query()->exists() || ! filled($client_id) || ! filled($client_secret)) {
            return;
        }

        SlackAppSetting::create([
            'client_id' => (string) $client_id,
            'client_secret' => (string) $client_secret,
            'signing_secret' => filled(env('SLACK_SIGNING_SECRET')) ? (string) env('SLACK_SIGNING_SECRET') : null,
            'redirect_uri' => filled(env('SLACK_REDIRECT_URI')) ? (string) env('SLACK_REDIRECT_URI') : null,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo, the copied credentials stay editable in Administration.
     */
    public function down(): void {}
};
