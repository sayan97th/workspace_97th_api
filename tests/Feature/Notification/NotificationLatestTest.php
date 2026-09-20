<?php

use App\Models\Notification;
use App\Models\User;

function createLatestTestNotification(User $recipient, array $attributes = []): Notification
{
    return Notification::create([
        'user_id' => $recipient->id,
        'actor_id' => User::factory()->create()->id,
        'type' => Notification::TYPE_MENTIONED,
        'action_label' => 'Mentioned you',
        'action_target' => 'in a comment',
        'link' => '/boards/1/pulses/2',
        ...$attributes,
    ]);
}

test('latest without after_id only returns the baseline id', function () {
    $user = User::factory()->create();
    createLatestTestNotification($user);
    $newest = createLatestTestNotification($user);

    $this->actingAs($user, 'api')->getJson('/api/notifications/latest')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.latest_id', (string) $newest->id);
});

test('latest returns only notifications newer than after_id, oldest first', function () {
    $user = User::factory()->create();
    $old = createLatestTestNotification($user);
    $first_new = createLatestTestNotification($user);
    $second_new = createLatestTestNotification($user);

    $this->actingAs($user, 'api')->getJson("/api/notifications/latest?after_id={$old->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $first_new->id)
        ->assertJsonPath('data.1.id', (string) $second_new->id)
        ->assertJsonPath('data.0.is_silenced', false)
        ->assertJsonPath('data.0.is_push_muted', false)
        ->assertJsonPath('meta.latest_id', (string) $second_new->id);
});

test('latest skips dismissed and snoozed notifications and other users', function () {
    $user = User::factory()->create();
    $baseline = createLatestTestNotification($user);
    createLatestTestNotification($user, ['dismissed_at' => now()]);
    createLatestTestNotification($user, ['snoozed_until' => now()->addHour()]);
    createLatestTestNotification(User::factory()->create());
    $visible = createLatestTestNotification($user);

    $this->actingAs($user, 'api')->getJson("/api/notifications/latest?after_id={$baseline->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $visible->id);
});

test('latest reports the push mute preference of the notification type', function () {
    $user = User::factory()->create(['notification_preferences' => [Notification::TYPE_MENTIONED.'_push' => false]]);
    $baseline = createLatestTestNotification($user, ['type' => Notification::TYPE_ASSIGNED]);
    createLatestTestNotification($user);

    $this->actingAs($user, 'api')->getJson("/api/notifications/latest?after_id={$baseline->id}")
        ->assertOk()
        ->assertJsonPath('data.0.is_push_muted', true);
});

test('latest requires authentication and validates its input', function () {
    $this->getJson('/api/notifications/latest')->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'api')
        ->getJson('/api/notifications/latest?after_id=abc&limit=99')
        ->assertUnprocessable();
});
