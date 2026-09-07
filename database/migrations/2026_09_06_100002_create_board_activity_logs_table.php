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
        Schema::create('board_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['board_id', 'created_at']);
            $table->foreign('board_id')->references('id')->on('workspace_navigation_items')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_activity_logs');
    }
};
