<?php

use App\Enums\EmailDigestFrequency;
use App\Jobs\SendEmailJob;
use App\Mail\Notifications\NotificationDigestEmail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDigestService;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;

function createInboxNotification(User $recipient, array $attributes = []): Notification
{
    // `created_at` is not mass assignable, so it is set on the model directly.
    $notification = new Notification([
        'user_id' => $recipient->id,
        'actor_id' => User::factory()->create()->id,
        'type' => Notification::TYPE_MENTIONED,
        'action_label' => 'Mentioned you',
        'action_target' => 'in a comment',
        'link' => '/boards/1/pulses/2',
        ...Arr::except($attributes, ['created_at']),
    ]);

    if (isset($attributes['created_at'])) {
        $notification->created_at = $attributes['created_at'];
    }

    $notification->save();

    return $notification;
}

test('the notification list is cursor paginated newest first', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $minutes_ago) {
        createInboxNotification($user, ['created_at' => now()->subMinutes($minutes_ago)]);
    }

    $first = $this->actingAs($user, 'api')->getJson('/api/notifications?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.has_more', true);

    $second = $this->actingAs($user, 'api')
        ->getJson('/api/notifications?limit=2&cursor='.$first->json('meta.next_cursor'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $third = $this->actingAs($user, 'api')
        ->getJson('/api/notifications?limit=2&cursor='.$second->json('meta.next_cursor'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);

    $ids = collect([$first, $second, $third])->flatMap(fn ($page) => collect($page->json('data'))->pluck('id'));
    expect($ids->unique())->toHaveCount(5);
});

test('the list filters by tab, unread state and search', function () {
    $user = User::factory()->create();
    $ada = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    createInboxNotification($user, ['actor_id' => $ada->id, 'type' => Notification::TYPE_MENTIONED]);
    createInboxNotification($user, ['type' => Notification::TYPE_REPLIED_THREAD, 'is_read' => true]);
    createInboxNotification($user, ['type' => Notification::TYPE_REACTIONS]);

    $this->actingAs($user, 'api')->getJson('/api/notifications?tab=replies')->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'replies');
    $this->actingAs($user, 'api')->getJson('/api/notifications?tab=reactions')->assertJsonCount(1, 'data');
    $this->actingAs($user, 'api')->getJson('/api/notifications?unread=1')->assertJsonCount(2, 'data');
    $this->actingAs($user, 'api')->getJson('/api/notifications?q=ada+love')->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor.id', $ada->id);
});

test('the filters endpoint lists the boards and people present in the inbox', function () {
    $user = User::factory()->create();
    $notification = createInboxNotification($user);

    $this->actingAs($user, 'api')->getJson('/api/notifications/filters')
        ->assertOk()
        ->assertJsonCount(1, 'data.actors')
        ->assertJsonPath('data.actors.0.id', $notification->actor_id);
});

test('a notification can be marked as unread again', function () {
    $user = User::factory()->create();
    $notification = createInboxNotification($user, ['is_read' => true, 'read_at' => now()]);

    $this->actingAs($user, 'api')->patchJson("/api/notifications/{$notification->id}/unread")
        ->assertOk()
        ->assertJsonPath('data.is_unread', true);

    expect($notification->fresh()->is_read)->toBeFalse();
    $this->actingAs($user, 'api')->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
});

test('a snoozed notification is hidden until it is woken as unread', function () {
    $user = User::factory()->create();
    $notification = createInboxNotification($user, ['is_read' => true, 'read_at' => now()]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/notifications/{$notification->id}/snooze", ['snoozed_until' => now()->addHour()->toIso8601String()])
        ->assertOk();

    $this->actingAs($user, 'api')->getJson('/api/notifications')->assertJsonCount(0, 'data');

    $this->travel(2)->hours();

    // Due, but not yet resurfaced: the elapsed snooze no longer hides it from the list.
    $this->actingAs($user, 'api')->getJson('/api/notifications')->assertJsonCount(1, 'data');

    expect(app(NotificationService::class)->wakeSnoozed())->toBe(1);

    $fresh = $notification->fresh();
    expect($fresh->snoozed_until)->toBeNull()->and($fresh->is_read)->toBeFalse();
});

test('snoozing requires a future date and the owner', function () {
    $user = User::factory()->create();
    $notification = createInboxNotification($user);

    $this->actingAs($user, 'api')
        ->patchJson("/api/notifications/{$notification->id}/snooze", ['snoozed_until' => now()->subHour()->toIso8601String()])
        ->assertUnprocessable();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/notifications/{$notification->id}/snooze", ['snoozed_until' => now()->addHour()->toIso8601String()])
        ->assertForbidden();
});

test('quiet hours keep the notification in the bell but send no email', function () {
    Queue::fake();

    $recipient = User::factory()->create([
        'timezone' => 'UTC',
        'quiet_hours_enabled' => true,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
    ]);

    $this->travelTo(now()->setTime(23, 30));

    $notification = app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: User::factory()->create(),
        type: Notification::TYPE_MENTIONED,
        board: null,
        action_label: 'Mentioned you',
        action_target: 'in a comment',
        link: '/boards/1/pulses/2',
    );

    expect($notification)->not->toBeNull();
    Queue::assertNotPushed(SendEmailJob::class);
});

test('quiet hours span midnight and respect the user timezone', function () {
    $user = User::factory()->make([
        'timezone' => 'America/New_York',
        'quiet_hours_enabled' => true,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
    ]);

    // 03:00 UTC is 23:00 in New York (EDT, UTC-4), inside the window.
    expect($user->isInQuietHours(now()->setDate(2026, 6, 15)->setTime(3, 0)))->toBeTrue();
    // 17:00 UTC is 13:00 in New York, outside it.
    expect($user->isInQuietHours(now()->setDate(2026, 6, 15)->setTime(17, 0)))->toBeFalse();

    $user->quiet_hours_enabled = false;
    expect($user->isInQuietHours(now()->setDate(2026, 6, 15)->setTime(3, 0)))->toBeFalse();
});

test('the daily digest lists only recent unread notifications of subscribed users', function () {
    Queue::fake();

    $subscribed = User::factory()->create(['email_digest_frequency' => 'daily']);
    $not_subscribed = User::factory()->create(['email_digest_frequency' => 'off']);

    createInboxNotification($subscribed);
    createInboxNotification($subscribed, ['is_read' => true]);
    createInboxNotification($subscribed, ['created_at' => now()->subDays(3)]);
    createInboxNotification($not_subscribed);

    expect(app(NotificationDigestService::class)->sendDue(EmailDigestFrequency::Daily))->toBe(1);

    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $subscribed->email
        && $job->mailable instanceof NotificationDigestEmail
        && $job->mailable->total_unread === 1);
});

test('the notification preferences endpoint saves quiet hours and the digest frequency', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', [
        'quiet_hours_enabled' => true,
        'quiet_hours_start' => '21:30',
        'quiet_hours_end' => '06:15',
        'email_digest_frequency' => 'weekly',
    ])->assertOk()->assertJsonPath('user.email_digest_frequency', 'weekly');

    $user->refresh();
    expect($user->quiet_hours_enabled)->toBeTrue()->and($user->quiet_hours_start)->toBe('21:30');

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', ['email_digest_frequency' => 'hourly'])->assertUnprocessable();
});

test('the digest email renders every listed notification and the overflow count', function () {
    $recipient = User::factory()->create(['first_name' => 'Ada', 'email_digest_frequency' => 'daily']);
    $notifications = collect([
        createInboxNotification($recipient, ['action_label' => 'Mentioned you', 'action_target' => 'in "Launch plan"']),
        createInboxNotification($recipient, ['action_label' => 'Replied to your update', 'action_target' => 'on "Roadmap"']),
    ])->each->load(['actor', 'board']);

    $mailable = new NotificationDigestEmail($recipient, new EloquentCollection($notifications->all()), 5, EmailDigestFrequency::Daily);

    $mailable->assertHasSubject('Your daily digest: 5 unread notifications');
    $mailable->assertSeeInHtml('Hi Ada');
    $mailable->assertSeeInHtml('in "Launch plan"', escape: true);
    $mailable->assertSeeInHtml('And 3 more waiting in your notifications.');
});

test('the wake and digest commands run and reject a bad frequency', function () {
    $this->artisan('notifications:wake-snoozed')->assertSuccessful();
    $this->artisan('notifications:send-digests', ['frequency' => 'daily'])->assertSuccessful();
    $this->artisan('notifications:send-digests', ['frequency' => 'hourly'])->assertFailed();
});
