<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ties an app user to their own Slack member id, proven through "Sign in with Slack"
     * rather than typed in by hand, so a notification for one person can never be routed
     * to someone else's Slack account. Deleted with the installation it belongs to.
     */
    public function up(): void
    {
        Schema::create('slack_user_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('slack_installation_id')->constrained('slack_installations')->cascadeOnDelete();
            $table->string('slack_user_id');
            $table->string('slack_display_name')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->index(['slack_installation_id', 'slack_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_user_links');
    }
};
