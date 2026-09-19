<?php

namespace App\Events;

use App\Models\BoardItemComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a comment or reply is posted on a board item, so every drawer
 * currently showing that item's Updates tab can offer a "N new updates" pill.
 * Delivered over the same `presence-board-item.{id}` channel the drawer
 * already joins for its live "seen by" list (see routes/channels.php), so
 * only people looking at that item receive it. The payload carries ids only:
 * the client refetches the thread when the viewer clicks the pill, which
 * keeps this broadcast tiny and never leaks comment content to a channel.
 */
class ItemCommentPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BoardItemComment $comment) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('board-item.'.$this->comment->item_id)];
    }

    public function broadcastAs(): string
    {
        return 'item_comment_posted';
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
