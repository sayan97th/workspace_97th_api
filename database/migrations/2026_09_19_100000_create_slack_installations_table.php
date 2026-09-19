<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per Slack workspace the account has installed the app into. The app is
     * single tenant, so in practice there is at most one row, see `SlackInstallation::current()`.
     */
    public function up(): void
    {
        Schema::create('slack_installations', function (Blueprint $table) {
            $table->id();
            $table->string('team_id')->unique();
            $table->string('team_name');
            $table->string('bot_user_id')->nullable();
            $table->text('bot_token'); // encrypted at rest by the model's `encrypted` cast
            $table->text('scopes')->nullable();
            $table->foreignId('installed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_installations');
    }
};
