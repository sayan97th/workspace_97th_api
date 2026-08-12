<?php

use App\Mail\SmtpTestMail;
use Illuminate\Support\Facades\Mail;

test('sends a test email to the address passed via --email', function () {
    Mail::fake();

    $this->artisan('app:test-smtp-email', ['--email' => 'jane.doe@example.com'])
        ->assertExitCode(0);

    Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mailable) {
        return $mailable->hasTo('jane.doe@example.com');
    });
});

test('prompts for the email address when --email is not provided', function () {
    Mail::fake();

    $this->artisan('app:test-smtp-email')
        ->expectsQuestion('Which email address should receive the test message?', 'jane.doe@example.com')
        ->assertExitCode(0);

    Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mailable) {
        return $mailable->hasTo('jane.doe@example.com');
    });
});

test('fails when the email address is invalid', function () {
    Mail::fake();

    $this->artisan('app:test-smtp-email', ['--email' => 'not-an-email'])
        ->assertExitCode(1);

    Mail::assertNothingSent();
});
