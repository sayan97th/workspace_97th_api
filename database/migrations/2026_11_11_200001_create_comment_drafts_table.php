<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unsent update and reply drafts, one per user and composer, so text typed
     * into an item's or a board's update box survives leaving the drawer,
     * reloading the page or switching devices (Slack's drafts). A draft is
     * removed as soon as it is sent or discarded.
     */
    public function up(): void
    {
        Schema::create('comment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('workspace_navigation_items')->cascadeOnDelete();
            // Null for the board's own discussion ("Board updates"), set for an item's Updates tab.
            $table->foreignId('item_id')->nullable()->constrained('board_items')->cascadeOnDelete();
            // The update a reply draft answers, null for the main "Write an update" box. Points at
            // `board_item_comments` or `board_comments` depending on `item_id`, so it has no foreign key.
            $table->unsignedBigInteger('parent_id')->nullable();
            // One composer per user: `board:{id}:item:{id|none}:parent:{id|none}`, see CommentDraft::keyFor().
            $table->string('draft_key', 120);
            $table->text('body');
            $table->json('mentioned_user_ids')->nullable();
            $table->json('notified_user_ids')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'draft_key']);
            $table->index(['user_id', 'board_id']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_drafts');
    }
};
