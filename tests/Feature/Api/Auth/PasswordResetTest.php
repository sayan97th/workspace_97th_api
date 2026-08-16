<?php

use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

test('forgot password queues a reset email for a known email', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('message', 'We have emailed your password reset link.');

    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $user->email
        && $job->mailable instanceof PasswordResetMail
        && $job->mailable->user->is($user));
});

test('the password reset email links to the frontend, not the Inertia app', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

    Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($user) {
        /** @var PasswordResetMail $mailable */
        $mailable = $job->mailable;

        expect($mailable->resetUrl)
            ->toStartWith(rtrim(config('app.frontend_url'), '/').'/reset-password/'.$mailable->token.'?')
            ->and($mailable->resetUrl)->toContain('email='.urlencode($user->email));

        return true;
    });
});

test('forgot password does not reveal whether an email exists', function () {
    Bus::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'We have emailed your password reset link.');

    Bus::assertNotDispatched(SendEmailJob::class);
});

test('a user can reset their password with a valid token', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

    Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($user) {
        /** @var PasswordResetMail $mailable */
        $mailable = $job->mailable;

        $this->postJson('/api/auth/reset-password', [
            'token' => $mailable->token,
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
