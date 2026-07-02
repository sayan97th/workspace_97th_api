<?php

use App\Jobs\SendEmailJob;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('dispatch queues the job onto the emails queue', function () {
    Queue::fake();

    SendEmailJob::dispatch(new class extends Mailable {}, 'recipient@example.com');

    Queue::assertPushedOn('emails', SendEmailJob::class);
});

test('dispatch with throttle delays jobs based on their position', function () {
    Queue::fake();

    SendEmailJob::dispatchWithThrottle(new class extends Mailable {}, 'recipient@example.com', position: 2);

    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->delay !== null);
});

test('handle sends the mailable to the recipient', function () {
    Mail::fake();

    $mailable = new class extends Mailable {};

    (new SendEmailJob($mailable, 'recipient@example.com'))->handle();

    Mail::assertSent($mailable::class, function ($mail) {
        return $mail->hasTo('recipient@example.com');
    });
});

test('failed logs the delivery failure', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('Email delivery failed', Mockery::on(fn (array $context) => $context['recipient'] === 'recipient@example.com'));

    $job = new SendEmailJob(new class extends Mailable {}, 'recipient@example.com');

    $job->failed(new Exception('SMTP connection refused'));
});

test('the job rate limits through the emails limiter', function () {
    $job = new SendEmailJob(new class extends Mailable {}, 'recipient@example.com');

    expect($job->middleware())->each->toBeInstanceOf(RateLimited::class);
});
