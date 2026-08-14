<?php

use App\Models\User;

test('an authenticated user can update their working status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->patchJson('/api/profile/working-status', [
        'working_status' => 'wfh',
        'working_status_dates' => 'Jul 12 - Jul 15',
        'disable_notifications_while_away' => true,
        'hide_online_status' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('user.working_status', 'wfh')
        ->assertJsonPath('user.working_status_dates', 'Jul 12 - Jul 15')
        ->assertJsonPath('user.disable_notifications_while_away', true)
        ->assertJsonPath('user.hide_online_status', true);

    $user->refresh();
    expect($user->working_status)->toBe('wfh');
    expect($user->disable_notifications_while_away)->toBeTrue();
});

test('working status update rejects an invalid status key', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson('/api/profile/working-status', ['working_status' => 'not-a-real-status'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('working_status');
});
