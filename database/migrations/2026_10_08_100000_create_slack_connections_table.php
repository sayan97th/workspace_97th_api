<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A member's own Slack account connection, made from the Automations center like monday.com's
     * "Connect your Slack account" screen. Each one points at the workspace it was made in, Slack
     * channel automations post through the connection they were created with, so a member can keep
     * an automation on one workspace while the account wide active workspace is another.
     */
    public function up(): void
    {
        Schema::create('slack_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slack_installation_id')->constrained()->cascadeOnDelete();
            $table->string('slack_user_id')->nullable();
            $table->string('slack_user_name')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slack_installation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_connections');
    }
};
