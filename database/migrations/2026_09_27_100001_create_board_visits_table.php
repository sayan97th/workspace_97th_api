<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per user and board, refreshed every time the user opens the
     * board. Powers the Home page's "Recently visited" list.
     */
    public function up(): void
    {
        Schema::create('board_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->timestamps();

            $table->unique(['user_id', 'board_id']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_visits');
    }
};
