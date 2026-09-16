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
        // One row per (automation, item, day) a `date_arrived` automation has
        // already fired for — see `BoardAutomationService::runDueDateTriggers()`.
        // Dedupes the daily scheduled check so it never notifies the same item
        // twice in one day if the command runs more than once.
        Schema::create('board_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('board_automations')->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained('board_items')->cascadeOnDelete();
            $table->date('ran_on');
            $table->timestamps();

            $table->unique(['automation_id', 'board_item_id', 'ran_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_runs');
    }
};
