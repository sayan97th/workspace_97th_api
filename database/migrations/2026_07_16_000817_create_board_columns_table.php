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
        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type')->default('text'); // text|status|people|date|tags|number|checkbox
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('width')->default(180);
            $table->json('config')->nullable();
            $table->boolean('hideable')->default(true);
            $table->boolean('pinnable')->default(true);
            $table->timestamps();

            $table->unique(['board_id', 'key']);
            $table->index(['board_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_columns');
    }
};
