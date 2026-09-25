<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Boards saved as a template from the board menu ("Save as a template").
     * `snapshot` holds the board's tabs, columns, groups and, optionally, its
     * items, so a new board can be built from it later. Built in templates
     * live in code (`BuiltInBoardTemplates`), not in this table.
     */
    public function up(): void
    {
        Schema::create('board_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('category', 40)->default('custom');
            $table->string('color', 20)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('source_board_id')->nullable();
            $table->boolean('includes_items')->default(false);
            $table->json('snapshot');
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_templates');
    }
};
