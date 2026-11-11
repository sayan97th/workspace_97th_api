<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;

/**
 * An item on a public board, plus a recipient and an actor who both belong to its workspace.
 *
 * @return array{0: BoardItem, 1: User, 2: User}
 */
function createNotificationPreviewItem(): array
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

    return [$board->items()->create(['group_id' => $group->id, 'name' => 'New item', 'position' => 0]), $recipient, $actor];
}

test('a mention notification carries a plain text preview of the update', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => "@Sam I don't think we **ever** got this [assigned](https://example.com).\n\nWho would take this on?",
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications?tab=mentioned')
        ->assertOk()
        ->assertJsonPath('data.0.type', Notification::TYPE_MENTIONED)
        ->assertJsonPath('data.0.preview', "@Sam I don't think we ever got this assigned. Who would take this on?");
});

test('the preview follows edits and disappears once the update is deleted', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'First draft',
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $comment = $item->comments()->latest('id')->firstOrFail();
    $comment->update(['body' => 'Edited text']);

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.preview', 'Edited text');

    $comment->delete();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.preview', null);
});

test('a long update is cut to the preview limit and a notification without a comment has no preview', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => str_repeat('word ', 200),
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    Notification::create([
        'user_id' => $recipient->id,
        'actor_id' => $actor->id,
        'type' => Notification::TYPE_ASSIGNED,
        'board_id' => $item->board_id,
        'action_label' => 'Assigned you',
        'action_target' => 'to "New item"',
        'link' => null,
    ]);

    $data = collect($this->actingAs($recipient, 'api')->getJson('/api/notifications')->assertOk()->json('data'))->keyBy('type');

    expect(mb_strlen($data[Notification::TYPE_MENTIONED]['preview']))->toBeLessThanOrEqual(Notification::PREVIEW_MAX_LENGTH + 3)
        ->and($data[Notification::TYPE_ASSIGNED]['preview'])->toBeNull();
});

test('searching notifications matches the text of the update behind them', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Please review the competitor brief',
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications?q=competitor')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($recipient, 'api')->getJson('/api/notifications?q=unrelated')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a notification names the item it is about for the card footer', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Take a look',
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.item_name', 'New item');

    $this->actingAs($recipient, 'api')->getJson('/api/notifications/filters')
        ->assertJsonPath('data.actors.0.id', $actor->id)
        ->assertJsonStructure(['data' => ['actors' => [['id', 'name', 'avatar_url', 'is_deactivated']]]]);
});

test('delete all dismisses every notification of the caller only', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();
    $attributes = ['actor_id' => $actor->id, 'type' => Notification::TYPE_ASSIGNED, 'board_id' => $item->board_id, 'action_label' => 'Assigned you', 'action_target' => 'to "New item"'];

    Notification::create([...$attributes, 'user_id' => $recipient->id]);
    Notification::create([...$attributes, 'user_id' => $recipient->id, 'snoozed_until' => now()->addDay(), 'saved_at' => now()]);
    $someone_else = Notification::create([...$attributes, 'user_id' => $actor->id]);

    $this->actingAs($recipient, 'api')->deleteJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.dismissed_count', 2)
        ->assertJsonPath('data.unread_count', 0);

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')->assertJsonCount(0, 'data');
    expect(Notification::where('user_id', $recipient->id)->whereNull('dismissed_at')->count())->toBe(0)
        ->and($someone_else->fresh()->dismissed_at)->toBeNull();
});

test('a notification is stored as a self-contained record with its target and snapshots', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Can you **check** this?',
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $comment = $item->comments()->latest('id')->firstOrFail();
    $notification = Notification::where('user_id', $recipient->id)->firstOrFail();

    expect($notification->only(['target_type', 'target_id', 'preview', 'item_name', 'board_name']))->toBe([
        'target_type' => Notification::TARGET_POST,
        'target_id' => $comment->id,
        'preview' => 'Can you check this?',
        'item_name' => 'New item',
        'board_name' => $item->board->label,
    ]);

    // The stored names are what the notification said when it was sent, not live joins.
    $item->update(['name' => 'Renamed item']);

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.item_name', 'New item')
        ->assertJsonPath('data.0.target', ['type' => Notification::TARGET_POST, 'id' => $comment->id]);
});

test('a notification without a comment targets its item as a project', function () {
    [$item] = createNotificationPreviewItem();

    expect(Notification::snapshotAttributes($item->board, $item, null))->toMatchArray([
        'target_type' => Notification::TARGET_PROJECT,
        'target_id' => $item->id,
        'preview' => null,
    ])->and(Notification::snapshotAttributes($item->board, null, null)['target_id'])->toBe($item->board_id);
});

test('restoring a deleted update brings its preview back', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Back again',
        'mentioned_user_ids' => [$recipient->id],
    ])->assertCreated();

    $comment = $item->comments()->latest('id')->firstOrFail();
    $comment->delete();
    expect(Notification::forComment($comment)->value('preview'))->toBeNull();

    $comment->restore();
    expect(Notification::forComment($comment)->value('preview'))->toBe('Back again');
});

test('an automation message is sent by Automations and shown instead of the sentence', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_AUTOMATION,
        board: $item->board,
        action_label: 'Automation "New request"',
        action_target: 'Hi, there is a new request on the KB Request board',
        link: null,
        board_item: $item,
        message: 'Hi, there is a new request on the KB Request board',
    );

    $notification = Notification::where('user_id', $recipient->id)->firstOrFail();
    expect($notification->sender_type)->toBe(Notification::SENDER_AUTOMATIONS)
        ->and($notification->action_label)->toBe('Automation "New request"');

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.actor.name', 'Automations')
        ->assertJsonPath('data.0.actor.is_system', true)
        ->assertJsonPath('data.0.action_label', '')
        ->assertJsonPath('data.0.action_target', '')
        ->assertJsonPath('data.0.preview', 'Hi, there is a new request on the KB Request board')
        ->assertJsonPath('data.0.item_name', 'New item');
});

test('an automation without a message keeps its sentence under Automations and people stay senders', function () {
    [$item, $recipient, $actor] = createNotificationPreviewItem();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_AUTOMATION,
        board: $item->board,
        action_label: 'Automation "Button clicked"',
        action_target: 'ran on "New item"',
        link: null,
        board_item: $item,
    );

    $this->actingAs($recipient, 'api')->getJson('/api/notifications')
        ->assertJsonPath('data.0.actor.name', 'Automations')
        ->assertJsonPath('data.0.action_label', 'Automation "Button clicked"')
        ->assertJsonPath('data.0.preview', null);

    expect(Notification::senderTypeOf(Notification::TYPE_MENTIONED))->toBe(Notification::SENDER_USER)
        ->and(Notification::senderTypeOf(Notification::TYPE_DUE_DATE_REMINDER))->toBe(Notification::SENDER_AUTOMATIONS);
});
