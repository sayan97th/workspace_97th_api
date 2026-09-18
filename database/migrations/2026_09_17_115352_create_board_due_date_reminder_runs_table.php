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
        // One row per (column, item, day) a Date column's reminder has already
        // notified for — see `DueDateReminderService::run()`. Dedupes the daily
        // scheduled check the same way `board_automation_runs` dedupes
        // `date_arrived` automations, so a second run the same day never
        // double-notifies an item.
        Schema::create('board_due_date_reminder_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained('board_items')->cascadeOnDelete();
            $table->date('ran_on');
            $table->timestamps();

            $table->unique(['column_id', 'board_item_id', 'ran_on'], 'board_due_date_reminder_runs_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_due_date_reminder_runs');
    }
};
