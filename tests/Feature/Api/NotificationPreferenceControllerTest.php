<?php

use App\Models\User;

test('an authenticated user can toggle a single notification preference', function () {
    $user = User::factory()->create(['notification_preferences' => ['mentioned_app' => true, 'mentioned_email' => true]]);

    $response = $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', [
        'preferences' => ['mentioned_email' => false],
    ]);

    $response->assertOk();

    $user->refresh();
    expect($user->notification_preferences)->toBe(['mentioned_app' => true, 'mentioned_email' => false]);
});

test('toggling one preference does not wipe out the others', function () {
    $user = User::factory()->create(['notification_preferences' => ['assigned_app' => true, 'invitations_app' => true]]);

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', [
        'preferences' => ['assigned_app' => false],
    ])->assertOk();

    expect($user->fresh()->notification_preferences)->toBe(['assigned_app' => false, 'invitations_app' => true]);
});

test('an authenticated user can toggle desktop notifications', function () {
    $user = User::factory()->create(['desktop_notifications_enabled' => false]);

    $this->actingAs($user, 'api')
        ->patchJson('/api/profile/notifications', ['desktop_notifications_enabled' => true])
        ->assertOk()
        ->assertJsonPath('user.desktop_notifications_enabled', true);
});

test('notification preference update rejects an unknown key', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson('/api/profile/notifications', ['preferences' => ['not_a_real_key' => true]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('preferences');
});
