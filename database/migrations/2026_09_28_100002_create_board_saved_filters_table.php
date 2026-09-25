<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A personal, named filter one user saved on a board (the Filter panel's
     * "Saved filters"). Private to its owner and independent from the shared
     * board views, so saving one never changes what anyone else sees.
     */
    public function up(): void
    {
        Schema::create('board_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filter_state');
            $table->timestamps();

            $table->index(['user_id', 'board_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_saved_filters');
    }
};
