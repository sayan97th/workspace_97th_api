<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boards a user hid from their Update Feed (Inbox) with the board row's
     * "Hide board updates from inbox". Feed settings lists them so they can
     * be shown again.
     */
    public function up(): void
    {
        Schema::create('feed_hidden_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_hidden_boards');
    }
};
