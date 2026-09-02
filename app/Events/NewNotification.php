<?php

namespace App\Events;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever {@see NotificationService::notify()}
 * creates a notification. Delivered over the recipient's private
 * `notifications.{user_id}` channel (see routes/channels.php), and queued
 * (not `ShouldBroadcastNow`), so delivery depends on a running queue worker.
 */
class NewNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'new_notification';
    }

    /**
     * Reuses {@see NotificationResource} so the websocket payload is
     * byte-identical to the REST `GET /api/notifications` payload, letting
     * the frontend handle both with a single mapper function.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new NotificationResource($this->notification))->resolve();
    }
}
