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
        Schema::create('board_group_collapse_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_view_id')->constrained('board_views')->cascadeOnDelete();
            // Ids of `board_groups` this user currently has collapsed on this
            // tab — only the (typically few) collapsed ones are stored, not
            // one row per table, so this stays cheap even on a board with
            // hundreds of tables.
            $table->json('collapsed_group_ids');
            $table->timestamps();

            $table->unique(['user_id', 'board_view_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_group_collapse_states');
    }
};
