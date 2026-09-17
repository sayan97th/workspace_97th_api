<?php

use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;

function createMuteTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('muting a board suppresses future notifications scoped to it', function () {
    $board = createMuteTestBoard();
    $recipient = User::factory()->create();
    $actor = User::factory()->create();

    $this->actingAs($recipient, 'api')
        ->postJson("/api/boards/{$board->id}/mute")
        ->assertOk();

    $this->assertDatabaseHas('board_notification_mutes', ['user_id' => $recipient->id, 'board_id' => $board->id]);

    $notification = app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: "/boards/{$board->id}",
    );

    expect($notification)->toBeNull();
    $this->assertDatabaseMissing('notifications', ['user_id' => $recipient->id, 'board_id' => $board->id]);
});

test('unmuting a board restores notification delivery', function () {
    $board = createMuteTestBoard();
    $recipient = User::factory()->create();
    $actor = User::factory()->create();

    $this->actingAs($recipient, 'api')->postJson("/api/boards/{$board->id}/mute")->assertOk();
    $this->actingAs($recipient, 'api')
        ->deleteJson("/api/boards/{$board->id}/mute")
        ->assertOk();

    $this->assertDatabaseMissing('board_notification_mutes', ['user_id' => $recipient->id, 'board_id' => $board->id]);

    $notification = app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: "/boards/{$board->id}",
    );

    expect($notification)->not->toBeNull();
});

test('muted boards are listed for the current user', function () {
    $board_one = createMuteTestBoard();
    $board_two = createMuteTestBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board_one->id}/mute")->assertOk();

    $this->actingAs($user, 'api')
        ->getJson('/api/boards/muted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.board_id', $board_one->id);
});

test('marking all notifications as read clears the unread count', function () {
    $user = User::factory()->create();
    $actor = User::factory()->create();
    $board = createMuteTestBoard();

    app(NotificationService::class)->notify(
        recipient: $user,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: "/boards/{$board->id}",
    );
    app(NotificationService::class)->notify(
        recipient: $user,
        actor: $actor,
        type: Notification::TYPE_REACTIONS,
        board: $board,
        action_label: 'Reacted to your update',
        action_target: 'on your update',
        link: "/boards/{$board->id}",
    );

    $this->actingAs($user, 'api')
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);

    $this->actingAs($user, 'api')
        ->patchJson('/api/notifications/read-all')
        ->assertOk();

    $this->actingAs($user, 'api')
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

test('dismissing a notification removes it from the list and the unread count', function () {
    $user = User::factory()->create();
    $actor = User::factory()->create();
    $board = createMuteTestBoard();

    $notification = app(NotificationService::class)->notify(
        recipient: $user,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: "/boards/{$board->id}",
    );

    $this->actingAs($user, 'api')
        ->deleteJson("/api/notifications/{$notification->id}")
        ->assertOk();

    $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    $this->assertNotNull($notification->fresh()->dismissed_at);

    $this->actingAs($user, 'api')
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($user, 'api')
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

test('a user cannot dismiss someone else\'s notification', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $actor = User::factory()->create();
    $board = createMuteTestBoard();

    $notification = app(NotificationService::class)->notify(
        recipient: $user,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: "/boards/{$board->id}",
    );

    $this->actingAs($stranger, 'api')
        ->deleteJson("/api/notifications/{$notification->id}")
        ->assertForbidden();
});
