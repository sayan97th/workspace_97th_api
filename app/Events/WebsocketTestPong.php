<?php

namespace App\Events;

use App\Http\Controllers\Admin\WebsocketTest\WebsocketTestController;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by {@see WebsocketTestController::ping()}
 * so the admin "Websocket test" screen can confirm a full round trip: HTTP
 * request in, broadcast out over Reverb, received back on the private
 * `websocket-test.{user_id}` channel. Uses {@see ShouldBroadcastNow} rather
 * than {@see ShouldBroadcast} so the ping
 * is not silently swallowed by a stalled/misconfigured queue worker, that
 * would defeat the purpose of the test.
 */
class WebsocketTestPong implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public User $recipient,
        public string $ping_id,
        public ?string $client_sent_at,
        public string $server_sent_at,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('websocket-test.'.$this->recipient->id)];
    }

    public function broadcastAs(): string
    {
        return 'websocket_test_pong';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ping_id' => $this->ping_id,
            'client_sent_at' => $this->client_sent_at,
            'server_sent_at' => $this->server_sent_at,
        ];
    }
}
