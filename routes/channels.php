<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private per-user channel used to deliver real-time notifications.
Broadcast::channel('notifications.{user_id}', function ($user, $user_id) {
    return (int) $user->id === (int) $user_id;
});

// Private per-user channel used to deliver real-time Update Feed entries.
Broadcast::channel('feed.{user_id}', function ($user, $user_id) {
    return (int) $user->id === (int) $user_id;
});
