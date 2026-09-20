<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per time an automation was asked to run, whatever came of it. Feeds the Manage
        // tab's "Run history" and usage numbers. The names and types are copied onto the row so the
        // history still reads correctly after the automation or the item is deleted, which is why
        // `automation_id` and `board_item_id` are nulled instead of cascaded.
        Schema::create('board_automation_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->nullable()->constrained('board_automations')->nullOnDelete();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->foreignId('board_item_id')->nullable()->constrained('board_items')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('automation_name')->nullable();
            $table->string('item_name')->nullable();
            $table->string('trigger_type');
            $table->string('action_type');
            $table->string('status', 16); // success|failed|skipped
            $table->text('message');
            $table->timestamps();

            $table->index(['board_view_id', 'created_at'], 'board_automation_run_logs_view_idx');
            $table->index(['automation_id', 'created_at'], 'board_automation_run_logs_automation_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_run_logs');
    }
};
