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
        Schema::create('board_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('color');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['board_id', 'label']);
            $table->index(['board_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_tags');
    }
};
