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
        // One row per body a comment had before an edit replaced it, so the
        // "(edited)" marker can show the comment's earlier versions.
        Schema::create('board_item_comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('board_item_comments')->cascadeOnDelete();
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
        Schema::dropIfExists('board_item_comment_revisions');
    }
};
