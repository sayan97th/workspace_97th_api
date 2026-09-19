<?php

use App\Events\NewNotification;
use App\Models\Notification;
use App\Models\User;

function createBulkTestNotification(User $recipient, array $attributes = []): Notification
{
    return Notification::create([
        'user_id' => $recipient->id,
        'actor_id' => User::factory()->create()->id,
        'type' => Notification::TYPE_MENTIONED,
        'action_label' => 'Mentioned you',
        'action_target' => 'in a comment',
        ...$attributes,
    ]);
}

test('a bulk action marks several notifications read and unread', function () {
    $user = User::factory()->create();
    $first = createBulkTestNotification($user);
    $second = createBulkTestNotification($user);
    $untouched = createBulkTestNotification($user);

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'read', 'ids' => [$first->id, $second->id]])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1)
        ->assertJsonCount(2, 'data.ids');

    expect($first->fresh()->is_read)->toBeTrue()
        ->and($second->fresh()->is_read)->toBeTrue()
        ->and($untouched->fresh()->is_read)->toBeFalse();

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'unread', 'ids' => [$first->id]])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);
});

test('a bulk dismiss hides the notifications from the list', function () {
    $user = User::factory()->create();
    $keep = createBulkTestNotification($user);
    $dismiss = createBulkTestNotification($user);

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'dismiss', 'ids' => [$dismiss->id]])->assertOk();

    $listed = $this->actingAs($user, 'api')->getJson('/api/notifications')->assertOk()->json('data');
    expect(collect($listed)->pluck('id')->all())->toBe([(string) $keep->id]);
});

test('a bulk action ignores notifications that belong to someone else', function () {
    $user = User::factory()->create();
    $stranger_notification = createBulkTestNotification(User::factory()->create());

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'dismiss', 'ids' => [$stranger_notification->id]])
        ->assertOk()
        ->assertJsonCount(0, 'data.ids');

    expect($stranger_notification->fresh()->dismissed_at)->toBeNull();
});

test('a bulk action validates the action and the size of the selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'explode', 'ids' => [1]])->assertUnprocessable();
    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'read', 'ids' => []])->assertUnprocessable();
    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'read', 'ids' => range(1, 101)])->assertUnprocessable();
});

test('the live payload flags a notification whose desktop push is turned off for its type', function () {
    $user = User::factory()->create(['notification_preferences' => ['mentioned_push' => false]]);
    $muted = createBulkTestNotification($user)->load(['actor', 'board', 'user']);
    $other = createBulkTestNotification($user, ['type' => Notification::TYPE_REACTIONS])->load(['actor', 'board', 'user']);

    expect((new NewNotification($muted))->broadcastWith()['is_push_muted'])->toBeTrue()
        ->and((new NewNotification($other))->broadcastWith()['is_push_muted'])->toBeFalse();
});

test('the desktop push, sound and tab badge preferences can be saved', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', [
        'preferences' => ['mentioned_push' => false, 'due_date_reminder_push' => true],
        'notification_sound_enabled' => true,
        'tab_badge_enabled' => false,
    ])
        ->assertOk()
        ->assertJsonPath('user.notification_preferences.mentioned_push', false)
        ->assertJsonPath('user.notification_sound_enabled', true)
        ->assertJsonPath('user.tab_badge_enabled', false);
});
