<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('forgot password sends a reset link for a known email', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('message', 'We have emailed your password reset link.');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the password reset email links to the frontend, not the Inertia app', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $action_url = $notification->toMail($user)->actionUrl;

        expect($action_url)
            ->toStartWith(rtrim(config('app.frontend_url'), '/').'/reset-password/'.$notification->token.'?')
            ->and($action_url)->toContain('email='.urlencode($user->email));

        return true;
    });
});

test('forgot password does not reveal whether an email exists', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'We have emailed your password reset link.');

    Notification::assertNothingSent();
});

test('a user can reset their password with a valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson('/api/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk()->assertJsonPath('message', 'Your password has been reset successfully.');

        return true;
    });

    expect(Hash::check('new-password123', $user->fresh()->password))->toBeTrue();
});

test('reset password fails with an invalid token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password123',
        'password_confirmation' => 'new-password123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('reset password requires a matching password confirmation', function () {
    $token = Password::broker()->createToken(User::factory()->create());

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'jane@example.com',
        'password' => 'new-password123',
        'password_confirmation' => 'different-password',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
