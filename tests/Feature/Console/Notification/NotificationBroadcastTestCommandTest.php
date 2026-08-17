<?php

use App\Models\Notification;
use App\Models\User;

test('broadcasts a test notification to every user when confirmed', function () {
    $users = User::factory()->count(3)->create();

    $this->artisan('notification:broadcast-test', ['--force' => true])
        ->assertExitCode(0);

    foreach ($users as $user) {
        expect(Notification::where('user_id', $user->id)->where('type', Notification::TYPE_TEST)->exists())
            ->toBeTrue();
    }

    expect(Notification::where('type', Notification::TYPE_TEST)->count())->toBe(3);
});

test('creates a test notification with no actor', function () {
    $user = User::factory()->create();

    $this->artisan('notification:broadcast-test', ['--force' => true])
        ->assertExitCode(0);

    $notification = Notification::where('user_id', $user->id)->where('type', Notification::TYPE_TEST)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->actor_id)->toBeNull()
        ->and($notification->is_read)->toBeFalse();
});

test('only sends to the user matched by --user', function () {
    $target = User::factory()->create();
    $other = User::factory()->create();

    $this->artisan('notification:broadcast-test', ['--user' => $target->id, '--force' => true])
        ->assertExitCode(0);

    expect(Notification::where('user_id', $target->id)->where('type', Notification::TYPE_TEST)->exists())->toBeTrue()
        ->and(Notification::where('user_id', $other->id)->where('type', Notification::TYPE_TEST)->exists())->toBeFalse();
});

test('does nothing when --user does not match any account', function () {
    $this->artisan('notification:broadcast-test', ['--user' => 999999, '--force' => true])
        ->assertExitCode(0);

    expect(Notification::where('type', Notification::TYPE_TEST)->exists())->toBeFalse();
});

test('asks for confirmation before broadcasting when --force is not passed', function () {
    User::factory()->create();

    $this->artisan('notification:broadcast-test')
        ->expectsConfirmation('This will broadcast a test notification to 1 user(s). Continue?', 'no')
        ->assertExitCode(0);

    expect(Notification::where('type', Notification::TYPE_TEST)->exists())->toBeFalse();
});
