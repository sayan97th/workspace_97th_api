<?php

use App\Jobs\SendEmailJob;
use App\Mail\Notifications\NotificationEmail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Queue;

test('an email is queued when the email preference is on', function () {
    Queue::fake();

    $recipient = User::factory()->create(['notification_preferences' => ['mentioned_app' => true, 'mentioned_email' => true]]);
    $actor = User::factory()->create();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: null,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: '/boards/1/pulses/2',
    );

    Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($recipient) {
        return $job->recipientEmail === $recipient->email && $job->mailable instanceof NotificationEmail;
    });
});

test('an email is queued by default when the preference is unset', function () {
    Queue::fake();

    $recipient = User::factory()->create(['notification_preferences' => []]);
    $actor = User::factory()->create();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_ASSIGNED,
        board: null,
        action_label: 'Assigned you',
        action_target: 'to "Task"',
        link: '/boards/1/pulses/2',
    );

    Queue::assertPushed(SendEmailJob::class);
});

test('no email is queued when the email preference is off', function () {
    Queue::fake();

    $recipient = User::factory()->create(['notification_preferences' => ['mentioned_app' => true, 'mentioned_email' => false]]);
    $actor = User::factory()->create();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: Notification::TYPE_MENTIONED,
        board: null,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: '/boards/1/pulses/2',
    );

    Queue::assertNotPushed(SendEmailJob::class);
});

test('no email is queued for actor-less system notifications', function () {
    Queue::fake();

    $recipient = User::factory()->create();

    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: null,
        type: Notification::TYPE_TEST,
        board: null,
        action_label: 'Test notification',
        action_target: 'for diagnostics',
        link: null,
    );

    Queue::assertNotPushed(SendEmailJob::class);
});
