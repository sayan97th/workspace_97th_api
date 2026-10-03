<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The credentials of the Slack app, managed from Administration > Integrations instead of
     * the API environment. A single row, see `SlackAppSetting::current()`. While it is empty
     * the SLACK_* environment values are used, see `App\Services\Slack\SlackAppCredentials`.
     */
    public function up(): void
    {
        Schema::create('slack_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret'); // encrypted at rest by the model's `encrypted` cast
            $table->text('signing_secret')->nullable(); // encrypted at rest as well
            $table->string('redirect_uri')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_app_settings');
    }
};
