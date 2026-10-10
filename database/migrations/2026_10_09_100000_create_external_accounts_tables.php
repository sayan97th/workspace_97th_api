<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Gmail, Outlook and Google Calendar integrations of the Automations center:
     * - `integration_app_settings`: the Google and Microsoft OAuth apps, saved by an administrator.
     * - `external_accounts`: a member's own Google or Microsoft account, with its tokens.
     * - `board_automation_email_receipts`: every email an "email is received" automation already
     *   turned into an item, so the same email is never imported twice.
     * - `board_item_calendar_events`: the Google Calendar event a sync automation keeps for an item.
     */
    public function up(): void
    {
        Schema::create('integration_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('tenant_id')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('external_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_user_id'], 'external_accounts_user_provider_unique');
        });

        Schema::create('board_automation_email_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_automation_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // Named by hand, the generated name is longer than the 64 characters MySQL allows.
            $table->unique(['board_automation_id', 'message_id'], 'automation_email_receipts_message_unique');
        });

        Schema::create('board_item_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained('board_items')->cascadeOnDelete();
            $table->foreignId('external_account_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_id');
            $table->string('event_id');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['board_automation_id', 'board_item_id'], 'item_calendar_events_automation_item_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_item_calendar_events');
        Schema::dropIfExists('board_automation_email_receipts');
        Schema::dropIfExists('external_accounts');
        Schema::dropIfExists('integration_app_settings');
    }
};
