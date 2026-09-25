<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One viewer's remembered, unsaved toolbar changes to one board view
     * ("Remember my filters"): the filter, sort, hidden columns and group by
     * they left it with. Replayed instead of the view's saved state the next
     * time they open it, until they reset it. At most one row per user and view.
     */
    public function up(): void
    {
        Schema::create('board_view_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            $table->json('filter_state')->nullable();
            $table->json('sort_state')->nullable();
            $table->json('hidden_column_ids')->nullable();
            $table->string('group_by_option_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'board_view_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_view_user_states');
    }
};
