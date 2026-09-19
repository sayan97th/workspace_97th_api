<?php

namespace App\Events;

use App\Models\BoardComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an update or reply is posted in a board's discussion, so every
 * open Board Discussion drawer can offer a "N new updates" pill. The board
 * counterpart of {@see ItemCommentPosted}: delivered over the
 * `presence-board-discussion.{id}` channel that drawer already joins, and
 * carrying ids only, so no comment content ever reaches the channel.
 */
class BoardCommentPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BoardComment $comment) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('board-discussion.'.$this->comment->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'board_comment_posted';
    }

    /**
     * @return array{comment_id: int, parent_id: int|null, author_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'comment_id' => $this->comment->id,
            'parent_id' => $this->comment->parent_id,
            'author_id' => $this->comment->user_id,
        ];
    }
}
