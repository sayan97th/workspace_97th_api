<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastAuthController extends Controller
{
    /**
     * POST /api/broadcasting/auth
     *
     * Authorizes a websocket client to subscribe to a private/presence
     * channel. This app authenticates via JWT (php-open-source-saver/jwt-auth),
     * not Sanctum session cookies, so the framework's stock `Broadcast::routes()`
     * helper (which relies on the web session guard) does not apply. This route
     * sits inside the same `auth:api` middleware group as the rest of the API,
     * so `$request->user()` is already resolved from the Bearer token by the
     * time `Broadcast::auth()` runs the `routes/channels.php` closures.
     */
    public function authenticate(Request $request)
    {
        $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
        ]);

        return Broadcast::auth($request);
    }
}
