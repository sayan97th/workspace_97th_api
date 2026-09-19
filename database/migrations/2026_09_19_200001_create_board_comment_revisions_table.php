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
        // Board discussion counterpart of `board_item_comment_revisions`.
        Schema::create('board_comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('board_comments')->cascadeOnDelete();
            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('body_written_at')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_comment_revisions');
    }
};
