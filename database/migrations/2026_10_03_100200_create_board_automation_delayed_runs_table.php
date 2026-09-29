<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The rest of an automation waiting behind a "wait" step: which action it continues from,
     * on which item, with what the trigger knew, and when. `automations:run-delayed` picks them up.
     */
    public function up(): void
    {
        Schema::create('board_automation_delayed_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('board_automations')->cascadeOnDelete();
            $table->unsignedBigInteger('board_id')->index();
            $table->unsignedBigInteger('board_view_id')->index();
            $table->unsignedBigInteger('board_item_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('run_uuid', 36)->nullable();
            $table->string('branch', 10)->default('then');
            $table->unsignedSmallInteger('next_action_index');
            $table->boolean('recheck_conditions')->default(false);
            $table->json('context')->nullable();
            $table->timestamp('run_at')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automation_delayed_runs');
    }
};
