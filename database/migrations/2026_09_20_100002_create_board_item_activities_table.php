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
        Schema::create('board_item_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('board_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('column_id')->nullable();
            $table->string('column_label');
            $table->string('column_type', 40);
            $table->string('old_display', 255)->nullable();
            $table->string('new_display', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_item_activities');
    }
};
