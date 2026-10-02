<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per link of a Dependency cell: how an item is scheduled from one of its
     * predecessors. Which predecessors an item has stays in the cell value itself (an array of
     * item ids), this table only adds the link's type and its lag in days.
     */
    public function up(): void
    {
        Schema::create('board_item_dependency_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('predecessor_id');
            $table->string('type', 2)->default('fs');
            $table->integer('lag_days')->default(0);
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('board_items')->cascadeOnDelete();
            $table->foreign('predecessor_id')->references('id')->on('board_items')->cascadeOnDelete();
            $table->unique(['column_id', 'item_id', 'predecessor_id'], 'dependency_links_unique');
            $table->index(['column_id', 'predecessor_id'], 'dependency_links_predecessor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_item_dependency_links');
    }
};
