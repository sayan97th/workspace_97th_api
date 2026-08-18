<?php

namespace App\Http\Controllers\Admin\WebsocketTest;

use App\Events\WebsocketTestPong;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Backs the admin "Websocket test" screen (frontend: /test/websocket).
 * `status()` reports the server-side broadcasting setup so it can be
 * compared against what the browser's Echo client is configured with, and
 * `ping()` triggers a real Reverb broadcast so the round trip (HTTP in,
 * websocket out) can be timed from the browser.
 */
class WebsocketTestController extends Controller
{
    /**
     * GET /api/admin/websocket-test/status
     *
     * Reports how this server is configured to broadcast, plus a raw TCP
     * reachability check of the Reverb server. This is the most common
     * source of "websocket doesn't work in production" reports: the
     * broadcast driver silently defaulting to "log" or "null" (broadcasts
     * are dropped, never sent) rather than an actual connectivity problem.
     */
    public function status(): JsonResponse
    {
        $broadcast_driver = (string) Config::get('broadcasting.default');
        $reverb_options = (array) Config::get('broadcasting.connections.reverb.options', []);

        $host = (string) ($reverb_options['host'] ?? '');
        $port = (int) ($reverb_options['port'] ?? 0);

        return response()->json([
            'data' => [
                'broadcast_driver' => $broadcast_driver,
                'is_reverb_driver' => $broadcast_driver === 'reverb',
                'reverb' => [
                    'app_key' => (string) Config::get('broadcasting.connections.reverb.key', ''),
                    'host' => $host,
                    'port' => $port,
                    'scheme' => (string) ($reverb_options['scheme'] ?? ''),
                ],
                'server_reachable' => $this->canReachReverb($host, $port),
                'checked_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * POST /api/admin/websocket-test/ping
     *
     * Dispatches {@see WebsocketTestPong} on the caller's private
     * `websocket-test.{user_id}` channel. The HTTP response confirms the
     * request reached Laravel; the frontend then waits for the broadcast
     * to arrive over the socket to confirm delivery actually works.
     */
    public function ping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ping_id' => ['required', 'string', 'max:100'],
            'client_sent_at' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $server_sent_at = now()->toISOString();

        broadcast(new WebsocketTestPong(
            recipient: $user,
            ping_id: $validated['ping_id'],
            client_sent_at: $validated['client_sent_at'] ?? null,
            server_sent_at: $server_sent_at,
        ));

        return response()->json([
            'data' => [
                'ping_id' => $validated['ping_id'],
                'broadcast_driver' => (string) Config::get('broadcasting.default'),
                'dispatched_at' => $server_sent_at,
                'channel' => 'websocket-test.'.$user->id,
            ],
        ]);
    }

    /**
     * Opens a short-lived raw TCP connection to the Reverb host/port to
     * confirm the server process is actually listening, independent of
     * whether the broadcast driver is configured correctly.
     */
    private function canReachReverb(string $host, int $port): bool
    {
        if ($host === '' || $port <= 0) {
            return false;
        }

        $connection = @fsockopen($host, $port, $error_code, $error_message, 2.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
