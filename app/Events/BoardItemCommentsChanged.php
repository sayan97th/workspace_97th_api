<?php

namespace App\Events;

use App\Services\Board\BoardItemCommentCounter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an item's updates change (one was posted, deleted, restored or a scheduled one went
 * live), so every open view of the board refreshes that row's updates counter without a page
 * refresh, see {@see BoardItemCommentCounter}.
 *
 * Unlike {@see ItemCommentPosted}, which only reaches people with that item's drawer open, this
 * goes to the whole board on the private `board-comments.{board_id}` channel. It carries the
 * absolute count (not a +1), so applying it twice, or after the poster's own HTTP response, is
 * harmless. Sent right away rather than through the queue, a stopped worker must not delay it.
 * No comment text is sent.
 */
class BoardItemCommentsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{item_id: int, comment_count: int, mentioned_user_ids: array<int, int>, changed_at: int}  $summary
     */
    public function __construct(
        public int $board_id,
        public array $summary,
        public string $action,
        public ?int $actor_id,
        public ?int $comment_id = null,
        public ?int $parent_id = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board-comments.'.$this->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'board_item_comments_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'board_id' => $this->board_id,
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'comment_id' => $this->comment_id,
            'parent_id' => $this->parent_id,
            ...$this->summary,
        ];
    }
}
