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
        Schema::create('board_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('trigger_type'); // status_changed|date_arrived
            $table->foreignId('trigger_column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->json('trigger_value')->nullable();
            $table->string('action_type'); // move_to_group|notify_person
            $table->json('action_params')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['board_view_id', 'trigger_type', 'trigger_column_id'], 'board_automations_trigger_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_automations');
    }
};
