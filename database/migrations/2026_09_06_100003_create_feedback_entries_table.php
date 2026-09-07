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
        Schema::create('feedback_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('board_id')->nullable();
            $table->text('message');
            $table->string('page_url')->nullable();
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('workspace_navigation_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_entries');
    }
};
