<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Arr;

function createSavedTestNotification(User $recipient, array $attributes = []): Notification
{
    return Notification::create([
        'user_id' => $recipient->id,
        'actor_id' => User::factory()->create()->id,
        'type' => Notification::TYPE_MENTIONED,
        'action_label' => 'Mentioned you',
        'action_target' => 'in a comment',
        'link' => '/boards/1/pulses/2',
        ...Arr::only($attributes, ['type', 'actor_id', 'is_read', 'snoozed_until', 'saved_at']),
    ]);
}

test('a notification can be saved for later and listed in the saved tab', function () {
    $user = User::factory()->create();
    $saved = createSavedTestNotification($user);
    createSavedTestNotification($user);

    $this->actingAs($user, 'api')->patchJson("/api/notifications/{$saved->id}/save")
        ->assertOk()
        ->assertJsonPath('data.is_saved', true);

    $this->actingAs($user, 'api')->getJson('/api/notifications?tab=saved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $saved->id)
        ->assertJsonPath('data.0.is_saved', true);

    $this->actingAs($user, 'api')->getJson('/api/notifications?tab=all')->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($user, 'api')->deleteJson("/api/notifications/{$saved->id}/save")->assertOk()->assertJsonPath('data.is_saved', false);
    $this->actingAs($user, 'api')->getJson('/api/notifications?tab=saved')->assertOk()->assertJsonCount(0, 'data');
});

test('mark all as read leaves saved notifications unread', function () {
    $user = User::factory()->create();
    $saved = createSavedTestNotification($user, ['saved_at' => now()]);
    $other = createSavedTestNotification($user);

    $this->actingAs($user, 'api')->patchJson('/api/notifications/read-all')->assertOk();

    expect($saved->fresh()->is_read)->toBeFalse()->and($other->fresh()->is_read)->toBeTrue();
});

test('bulk save and unsave apply to the selection and dismissing clears the saved mark', function () {
    $user = User::factory()->create();
    $first = createSavedTestNotification($user);
    $second = createSavedTestNotification($user);

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'save', 'ids' => [$first->id, $second->id]])->assertOk();
    expect($first->fresh()->saved_at)->not->toBeNull()->and($second->fresh()->saved_at)->not->toBeNull();

    $this->actingAs($user, 'api')->postJson('/api/notifications/bulk', ['action' => 'unsave', 'ids' => [$first->id]])->assertOk();
    expect($first->fresh()->saved_at)->toBeNull();

    $this->actingAs($user, 'api')->deleteJson("/api/notifications/{$second->id}")->assertOk();
    expect($second->fresh()->saved_at)->toBeNull()->and($second->fresh()->dismissed_at)->not->toBeNull();
});

test('nobody can save another persons notification', function () {
    $owner = User::factory()->create();
    $notification = createSavedTestNotification($owner);

    $this->actingAs(User::factory()->create(), 'api')->patchJson("/api/notifications/{$notification->id}/save")->assertForbidden();
});

test('the summary counts what is waiting for the user', function () {
    $user = User::factory()->create();
    $ada = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    createSavedTestNotification($user, ['actor_id' => $ada->id]);
    createSavedTestNotification($user, ['actor_id' => $ada->id]);
    createSavedTestNotification($user, ['type' => Notification::TYPE_REPLIED_THREAD, 'actor_id' => $ada->id]);
    createSavedTestNotification($user, ['type' => Notification::TYPE_DUE_DATE_REMINDER, 'actor_id' => null]);
    createSavedTestNotification($user, ['is_read' => true]);
    createSavedTestNotification($user, ['saved_at' => now()]);
    createSavedTestNotification($user, ['snoozed_until' => now()->addHour()]);
    createSavedTestNotification(User::factory()->create());

    $this->actingAs($user, 'api')->getJson('/api/notifications/summary')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 5)
        ->assertJsonPath('data.mentions', 3)
        ->assertJsonPath('data.replies', 1)
        ->assertJsonPath('data.due_reminders', 1)
        ->assertJsonPath('data.saved_count', 1)
        ->assertJsonPath('data.snoozed_count', 1)
        ->assertJsonPath('data.today_count', 6)
        ->assertJsonPath('data.top_actors.0.id', $ada->id)
        ->assertJsonPath('data.top_actors.0.count', 3);
});
