<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;

/** An item in the "Ready to Launch" group of a fresh board whose workspace holds every given member. */
function createMondayRecordItem(User ...$members): BoardItem
{
    $workspace = Workspace::factory()->create();
    foreach ($members as $member) {
        $workspace->users()->attach($member->id, ['role' => 'member']);
    }

    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Project Overview',
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Ready to Launch']);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Jelly Fish Season', 'position' => 0]);
}

test('an update keeps a plain text body next to its formatted body, also after an edit', function () {
    $author = User::factory()->create();
    $item = createMondayRecordItem($author);

    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => '**Outgoing Email** sent to [the client](https://example.com)']);
    expect($comment->fresh()->text_body)->toBe('Outgoing Email sent to the client');

    $comment->update(['body' => 'Yay!']);
    expect($comment->fresh()->text_body)->toBe('Yay!');
});

test('the feed credits an automation update to Automations and names the group', function () {
    $viewer = User::factory()->create();
    $owner = User::factory()->create();
    $item = createMondayRecordItem($viewer, $owner);

    BoardItemComment::create([
        'item_id' => $item->id,
        'user_id' => $owner->id,
        'sender_type' => Notification::SENDER_AUTOMATIONS,
        'body' => 'Outgoing Email',
    ]);

    $card = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?tab=account')->assertOk()->json('data.0');

    expect($card['actor']['name'])->toBe('Automations')
        ->and($card['actor']['is_system'])->toBeTrue()
        ->and($card['sender_type'])->toBe('automations')
        ->and($card['text_body'])->toBe('Outgoing Email')
        ->and($card['group']['name'])->toBe('Ready to Launch')
        ->and($card['item']['name'])->toBe('Jelly Fish Season');
});

test('a notification about a reply stores the title, the group and the thread it belongs to', function () {
    $recipient = User::factory()->create();
    $actor = User::factory()->create(['first_name' => 'Lindy', 'last_name' => 'Kr']);
    $item = createMondayRecordItem($recipient, $actor);
    $root = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $recipient->id, 'body' => 'Outgoing Email']);
    $reply = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $actor->id, 'parent_id' => $root->id, 'body' => 'Yay!']);

    $notification = app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_REPLIED_UPDATE,
        board: $item->board,
        action_label: 'replied to your update on',
        action_target: 'Jelly Fish Season',
        link: "/boards/{$item->board_id}/pulses/{$item->id}",
        board_item: $item,
        comment: $reply,
    );

    expect($notification->title)->toBe('Lindy Kr replied to your update on Jelly Fish Season')
        ->and($notification->group_name)->toBe('Ready to Launch')
        ->and($notification->thread_id)->toBe($root->id)
        ->and($notification->target_type)->toBe(Notification::TARGET_POST)
        ->and($notification->target_id)->toBe($reply->id);

    $payload = $this->actingAs($recipient, 'api')->getJson('/api/notifications')->assertOk()->json('data.0');

    expect($payload['title'])->toBe('Lindy Kr replied to your update on Jelly Fish Season')
        ->and($payload['group']['name'])->toBe('Ready to Launch')
        ->and($payload['item']['name'])->toBe('Jelly Fish Season')
        ->and($payload['update'])->toBe(['id' => "ic-{$reply->id}", 'thread_id' => "ic-{$root->id}"]);
});

test('a notification about an automation update is sent as Automations', function () {
    $recipient = User::factory()->create();
    $owner = User::factory()->create();
    $item = createMondayRecordItem($recipient, $owner);
    $comment = BoardItemComment::create([
        'item_id' => $item->id,
        'user_id' => $owner->id,
        'sender_type' => Notification::SENDER_AUTOMATIONS,
        'body' => '@Someone Outgoing Email',
    ]);

    $notification = app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $owner,
        type: Notification::TYPE_MENTIONED,
        board: $item->board,
        action_label: 'mentioned you on',
        action_target: 'Jelly Fish Season',
        link: null,
        comment: $comment,
    );

    expect($notification->sender_type)->toBe(Notification::SENDER_AUTOMATIONS)
        ->and($notification->title)->toBe('Automations mentioned you on Jelly Fish Season');
});
