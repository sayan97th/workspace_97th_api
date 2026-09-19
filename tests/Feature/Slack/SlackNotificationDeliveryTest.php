<?php

use App\Jobs\SendSlackMessageJob;
use App\Models\BoardNotificationMute;
use App\Models\Notification;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.frontend_url' => 'http://frontend.test']);
});

function slackLinkedUser(string $slack_user_id = 'U777', array $attributes = []): User
{
    $installation = SlackInstallation::current() ?? SlackInstallation::create([
        'team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-secret-token',
    ]);

    $user = User::factory()->create($attributes);
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => $slack_user_id]);

    return $user;
}

function notifyMention(User $recipient, User $actor, ?WorkspaceNavigationItem $board = null, string $type = Notification::TYPE_MENTIONED): void
{
    app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $actor,
        type: $type,
        board: $board,
        action_label: 'Mentioned you',
        action_target: 'in a comment on "Launch plan"',
        link: '/boards/1/pulses/2',
    );
}

test('tagging a linked member sends them a slack direct message', function () {
    Queue::fake();
    $amanda = slackLinkedUser('UAMANDA');
    $actor = User::factory()->create(['first_name' => 'Ernesto', 'last_name' => 'Afane']);

    notifyMention($amanda, $actor);

    Queue::assertPushed(SendSlackMessageJob::class, function (SendSlackMessageJob $job) {
        return $job->channel === 'UAMANDA'
            && str_contains($job->text, 'Ernesto Afane')
            && str_contains($job->text, 'mentioned you')
            && $job->blocks[0]['text']['text'] === '*Ernesto Afane* mentioned you in a comment on "Launch plan"'
            && end($job->blocks)['elements'][0]['url'] === 'http://frontend.test/boards/1/pulses/2';
    });
});

test('a member who did not link slack gets no slack message', function () {
    Queue::fake();
    SlackInstallation::create(['team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-secret-token']);

    notifyMention(User::factory()->create(), User::factory()->create());

    Queue::assertNotPushed(SendSlackMessageJob::class);
});

test('nothing is sent while slack is not connected at all', function () {
    Queue::fake();

    notifyMention(User::factory()->create(), User::factory()->create());

    Queue::assertNotPushed(SendSlackMessageJob::class);
});

test('the slack preference for that notification type switches slack off', function () {
    Queue::fake();
    $user = slackLinkedUser('U777', ['notification_preferences' => ['mentioned_slack' => false]]);

    notifyMention($user, User::factory()->create());
    Queue::assertNotPushed(SendSlackMessageJob::class);

    // Another type is unaffected, slack defaults to on like the app and email channels.
    notifyMention($user, User::factory()->create(), null, Notification::TYPE_ASSIGNED);
    Queue::assertPushed(SendSlackMessageJob::class, 1);
});

test('slack respects muted boards and self notifications', function () {
    Queue::fake();
    $user = slackLinkedUser();
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    BoardNotificationMute::create(['user_id' => $user->id, 'board_id' => $board->id]);

    notifyMention($user, User::factory()->create(), $board);
    notifyMention($user, $user);

    Queue::assertNotPushed(SendSlackMessageJob::class);
});

test('the queued job posts to slack with the bot token and escapes markup', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);
    $user = slackLinkedUser('UAMANDA');
    $actor = User::factory()->create(['first_name' => '<!channel>', 'last_name' => 'Evil & Co']);

    notifyMention($user, $actor);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request->hasHeader('Authorization', 'Bearer xoxb-secret-token')
            && $request['channel'] === 'UAMANDA'
            && str_contains($request['blocks'][0]['text']['text'], '&lt;!channel&gt; Evil &amp; Co')
            && ! str_contains($request['blocks'][0]['text']['text'], '<!channel>');
    });
});

test('a revoked token removes the installation instead of retrying forever', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'token_revoked'])]);
    $user = slackLinkedUser();

    notifyMention($user, User::factory()->create());

    expect(SlackInstallation::count())->toBe(0);
    Http::assertSentCount(1);
});

test('a permanent slack error is dropped without failing the notification', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found'])]);
    $user = slackLinkedUser();

    notifyMention($user, User::factory()->create());

    expect(SlackInstallation::count())->toBe(1);
    $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => Notification::TYPE_MENTIONED]);
});

test('preference keys accept the slack channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', ['preferences' => ['mentioned_slack' => false]])
        ->assertOk();

    expect($user->fresh()->notification_preferences)->toMatchArray(['mentioned_slack' => false]);

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', ['preferences' => ['mentioned_sms' => true]])
        ->assertUnprocessable();
});
