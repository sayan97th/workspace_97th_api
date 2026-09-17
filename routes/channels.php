<?php

use App\Models\BoardItem;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
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

// Private per-user channel used by the admin "Websocket test" screen to
// receive the pong broadcast by WebsocketTestController::ping().
Broadcast::channel('websocket-test.{user_id}', function ($user, $user_id) {
    return (int) $user->id === (int) $user_id;
});

// Private per-user channel used by the "Import items" wizard's progress bar
// to receive BoardImportProgressUpdated broadcasts from ProcessBoardImportJob.
Broadcast::channel('board-import.{user_id}', function ($user, $user_id) {
    return (int) $user->id === (int) $user_id;
});

// Presence channel powering the Table view's face-pile: every workspace
// member who joins is broadcast to every other joiner via here/joining/
// leaving, so the UI shows who else is currently on this board.
Broadcast::channel('presence-board.{board_id}', function (User $user, int $board_id) {
    $board = WorkspaceNavigationItem::find($board_id);
    if (! $board) {
        return false;
    }

    $is_member = $user->workspaces()->where('workspaces.id', $board->workspace_id)->exists();
    if (! $is_member) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->full_name,
        'avatar' => $user->profile_photo_url,
    ];
});

// Presence channel powering the item drawer's live "seen by" avatar list and
// typing indicator — joined only while that item's Updates tab is open.
Broadcast::channel('presence-board-item.{board_item_id}', function (User $user, int $board_item_id) {
    $board_item = BoardItem::find($board_item_id);
    if (! $board_item) {
        return false;
    }

    $is_member = $user->workspaces()->where('workspaces.id', $board_item->board->workspace_id)->exists();
    if (! $is_member) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->full_name,
        'avatar' => $user->profile_photo_url,
    ];
});

// Presence channel powering the board discussion drawer's live "seen by"
// avatar list and typing indicator — joined only while the drawer is open.
Broadcast::channel('presence-board-discussion.{board_id}', function (User $user, int $board_id) {
    $board = WorkspaceNavigationItem::find($board_id);
    if (! $board) {
        return false;
    }

    $is_member = $user->workspaces()->where('workspaces.id', $board->workspace_id)->exists();
    if (! $is_member) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->full_name,
        'avatar' => $user->profile_photo_url,
    ];
});
