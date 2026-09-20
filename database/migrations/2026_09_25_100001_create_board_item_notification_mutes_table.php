<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user muting one item silences every future notification about it, its
     * comment thread included, without muting the rest of the board.
     */
    public function up(): void
    {
        Schema::create('board_item_notification_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained('board_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'board_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_item_notification_mutes');
    }
};
