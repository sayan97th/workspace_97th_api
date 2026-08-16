<?php

use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $user->email
        && $job->mailable instanceof PasswordResetMail);
});

test('reset password screen can be rendered', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
        /** @var PasswordResetMail $mailable */
        $mailable = $job->mailable;

        $response = $this->get(route('password.reset', $mailable->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($user) {
        /** @var PasswordResetMail $mailable */
        $mailable = $job->mailable;

        $response = $this->post(route('password.update'), [
            'token' => $mailable->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
