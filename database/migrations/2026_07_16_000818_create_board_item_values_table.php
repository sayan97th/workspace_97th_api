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
        Schema::create('board_item_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('board_items')->cascadeOnDelete();
            $table->unique(['item_id', 'column_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_item_values');
    }
};
