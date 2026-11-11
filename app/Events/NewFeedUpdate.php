<?php

namespace App\Events;

use App\Http\Resources\FeedUpdateResource;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Services\Feed\FeedService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per recipient whenever {@see FeedService}
 * delivers a new (or newly-due) comment/reply to the Update Feed. Delivered
 * over the recipient's private `feed.{user_id}` channel (see
 * routes/channels.php) — the sibling of {@see NewNotification}, but carrying
 * the full update content instead of a one-line action summary.
 */
class NewFeedUpdate implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public BoardItemComment|BoardComment $comment,
        public User $recipient,
        /** @var array{total: int, entries: array<int, array<string, mixed>>}|null The item changes behind a top-level item update, resolved once by the sender. */
        public ?array $activity = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('feed.'.$this->recipient->id)];
    }

    public function broadcastAs(): string
    {
        return 'new_feed_update';
    }

    /**
     * Resolved for {@see $recipient} specifically, so `is_unread` /
     * `is_mentioned` / `is_bookmarked` are correct for whoever receives this
     * particular broadcast, even though the same comment is pushed to many
     * recipients' channels. A reply also carries `thread`, the card of its
     * whole thread (the top-level update with every reply), so the feed can
     * show it inline under its update instead of as a card of its own.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $resource = (new FeedUpdateResource($this->comment))->forViewer($this->recipient);
        $payload = ($this->activity !== null ? $resource->withActivity($this->activity) : $resource)->resolve();

        if ($this->comment->parent_id !== null) {
            $payload['thread'] = FeedUpdateResource::threadPayload($this->comment, $this->recipient);
        }

        return $payload;
    }
}
