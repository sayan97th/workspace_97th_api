<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: BoardItem, 1: BoardItem, 2: User}
 */
function createItemMuteTestBoard(): array
{
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [
        $board->items()->create(['group_id' => $group->id, 'name' => 'Noisy', 'position' => 0]),
        $board->items()->create(['group_id' => $group->id, 'name' => 'Quiet', 'position' => 1]),
        $member,
    ];
}

test('muting an item silences its comment notifications only', function () {
    [$noisy, $other, $recipient] = createItemMuteTestBoard();
    $actor = User::factory()->create();
    $noisy->board->workspace->users()->attach($actor->id, ['role' => 'member']);

    $this->actingAs($recipient, 'api')
        ->postJson("/api/boards/{$noisy->board_id}/items/{$noisy->id}/mute")
        ->assertOk();

    $this->assertDatabaseHas('board_item_notification_mutes', ['user_id' => $recipient->id, 'board_item_id' => $noisy->id]);

    $noisy_update = $noisy->comments()->create(['user_id' => $recipient->id, 'body' => 'Mine']);
    $other_update = $other->comments()->create(['user_id' => $recipient->id, 'body' => 'Mine too']);

    foreach ([[$noisy, $noisy_update], [$other, $other_update]] as [$item, $update]) {
        $this->actingAs($actor, 'api')->postJson(
            "/api/boards/{$item->board_id}/items/{$item->id}/comments",
            ['body' => 'A reply', 'parent_id' => $update->id],
        )->assertCreated();
    }

    $received = Notification::where('user_id', $recipient->id)->get();
    expect($received)->toHaveCount(1)
        ->and($received->first()->board_item_id)->toBe($other->id);

    $this->actingAs($recipient, 'api')
        ->getJson('/api/boards/muted-items')
        ->assertOk()
        ->assertJsonPath('data.0.board_item_id', $noisy->id)
        ->assertJsonPath('data.0.item_name', 'Noisy');

    $this->actingAs($recipient, 'api')->deleteJson("/api/boards/{$noisy->board_id}/items/{$noisy->id}/mute")->assertOk();
    $this->assertDatabaseMissing('board_item_notification_mutes', ['user_id' => $recipient->id]);
});

test('only workspace members can mute an item', function () {
    [$item] = createItemMuteTestBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/mute")
        ->assertForbidden();
});

test('a notification carries its item, mute state and the comment to reply to', function () {
    [$item, , $recipient] = createItemMuteTestBoard();
    $actor = User::factory()->create();
    $item->board->workspace->users()->attach($actor->id, ['role' => 'member']);

    $update = $item->comments()->create(['user_id' => $recipient->id, 'body' => 'Mine']);
    $this->actingAs($actor, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Answer', 'parent_id' => $update->id],
    )->assertCreated();

    $reply_id = $item->comments()->where('parent_id', $update->id)->value('id');

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.board_item_id', $item->id)
        ->assertJsonPath('data.0.is_item_muted', false)
        ->assertJsonPath('data.0.reply_to', "ic-{$reply_id}");

    $this->actingAs($recipient, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/mute")->assertOk();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.is_item_muted', true);
});

test('an inline reply from a notification lands on the thread and follows the item', function () {
    [$item, , $recipient] = createItemMuteTestBoard();
    $actor = User::factory()->create();
    $item->board->workspace->users()->attach($actor->id, ['role' => 'member']);

    $update = $item->comments()->create(['user_id' => $recipient->id, 'body' => 'Mine']);
    $this->actingAs($actor, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Answer', 'parent_id' => $update->id],
    )->assertCreated();

    $reply_to = $this->actingAs($recipient, 'api')->getJson('/api/notifications')->json('data.0.reply_to');

    $this->actingAs($recipient, 'api')
        ->postJson("/api/feed/updates/{$reply_to}/reply", ['body' => 'Thanks, done'])
        ->assertCreated();

    // The reply hangs off the top-level update, not off the reply it answered.
    $this->assertDatabaseHas('board_item_comments', ['user_id' => $recipient->id, 'parent_id' => $update->id, 'body' => 'Thanks, done']);
    expect($recipient->feedFollows()->where('target_type', 'item')->where('target_id', $item->id)->exists())->toBeTrue();
});

test('an inline reply is refused outside the viewer workspaces', function () {
    [$item] = createItemMuteTestBoard();
    $author = User::factory()->create();
    $update = $item->comments()->create(['user_id' => $author->id, 'body' => 'Private']);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/feed/updates/ic-{$update->id}/reply", ['body' => 'Sneaky'])
        ->assertForbidden();
});
