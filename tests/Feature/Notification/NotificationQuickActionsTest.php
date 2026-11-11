<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * The one click actions on a notification card: Like (the comment behind it),
 * Mark as read and Save for later.
 *
 * @return array{0: BoardItem, 1: User, 2: User}
 */
function createQuickActionsNotification(): array
{
    $recipient = User::factory()->create();
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($recipient->id, ['role' => 'member']);
    $workspace->users()->attach($actor->id, ['role' => 'member']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Launch', 'position' => 0]);

    $update = $item->comments()->create(['user_id' => $recipient->id, 'body' => 'Mine']);
    test()->actingAs($actor, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Answer', 'parent_id' => $update->id],
    )->assertCreated();

    return [$item, $recipient, $actor];
}

test('a notification reports whether the viewer liked the comment behind it', function () {
    [, $recipient] = createQuickActionsNotification();

    $response = $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', false);
    $reply_to = $response->json('data.0.reply_to');

    $this->actingAs($recipient, 'api')->postJson("/api/feed/updates/{$reply_to}/like")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.is_liked', true);

    // A second click takes the like back.
    $this->actingAs($recipient, 'api')->postJson("/api/feed/updates/{$reply_to}/like")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.is_liked', false);
});

test('a like by someone else does not count as the viewer like', function () {
    [, $recipient, $actor] = createQuickActionsNotification();
    $reply_to = $this->actingAs($recipient, 'api')->getJson('/api/notifications')->json('data.0.reply_to');

    $this->actingAs($actor, 'api')->postJson("/api/feed/updates/{$reply_to}/like")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.is_liked', false);
});

test('save for later and mark as read are one request each', function () {
    [, $recipient] = createQuickActionsNotification();
    $id = $this->actingAs($recipient, 'api')->getJson('/api/notifications')->json('data.0.id');

    $this->actingAs($recipient, 'api')->patchJson("/api/notifications/{$id}/save")->assertOk();
    $this->actingAs($recipient, 'api')->patchJson("/api/notifications/{$id}/read")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications?tab=saved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_saved', true)
        ->assertJsonPath('data.0.is_unread', false);

    $this->actingAs($recipient, 'api')->deleteJson("/api/notifications/{$id}/save")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications?tab=saved')
        ->assertJsonCount(0, 'data');
});
