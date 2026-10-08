<?php

use App\Enums\SlackNotificationTest;
use App\Jobs\SendSlackMessageJob;
use App\Models\AuditLog;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Services\Slack\SlackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    saveSlackAppCredentials();
});

function makeSuiteInstallation(array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => 'T100',
        'team_name' => 'Acme',
        'bot_user_id' => 'UBOT',
        'bot_token' => 'xoxb-suite-token',
        'scopes' => implode(',', SlackService::BOT_SCOPES),
    ], $overrides));
}

function makeSuiteAdmin(): User
{
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin']);
    $admin->assignRole('admin');

    return $admin;
}

function makeLinkedRecipient(SlackInstallation $installation, array $attributes = []): User
{
    $recipient = User::factory()->create(array_merge(['first_name' => 'Rita', 'last_name' => 'Receiver', 'email' => 'rita@example.com'], $attributes));
    SlackUserLink::create([
        'user_id' => $recipient->id,
        'slack_installation_id' => $installation->id,
        'slack_user_id' => 'U300',
        'slack_display_name' => 'Rita',
        'linked_at' => now(),
    ]);

    return $recipient;
}

function runSuiteTest($test_case, User $admin, string $test, array $payload = [])
{
    return $test_case->actingAs($admin, 'api')->postJson("/api/integrations/slack/diagnostics/notification-tests/{$test}", $payload);
}

/**
 * @return array<string, mixed>
 */
function slackMessageResponse(string $channel = 'D300', string $ts = '1700000000.000100'): array
{
    return ['ok' => true, 'channel' => $channel, 'ts' => $ts];
}

test('only administrators can list and run the slack notification tests', function () {
    $member = User::factory()->create();
    $member->assignRole('client');

    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/diagnostics/notification-tests')->assertForbidden();
    $this->actingAs($member, 'api')->postJson('/api/integrations/slack/diagnostics/notification-tests/bot_identity')->assertForbidden();
});

test('the catalog lists every test with the scopes the active workspace is missing for it', function () {
    makeSuiteInstallation(['scopes' => 'chat:write,chat:write.public,channels:read,groups:read']);

    $response = $this->actingAs(makeSuiteAdmin(), 'api')->getJson('/api/integrations/slack/diagnostics/notification-tests')->assertOk();
    $tests = collect($response->json('tests'))->keyBy('key');

    expect($tests)->toHaveCount(count(SlackNotificationTest::cases()))
        ->and($response->json('workspace.team_name'))->toBe('Acme')
        ->and($tests['reaction']['missing_scopes'])->toBe(['reactions:write', 'reactions:read'])
        ->and($tests['file_upload']['missing_scopes'])->toBe(['files:write', 'files:read'])
        ->and($tests['direct_message']['missing_scopes'])->toBe([])
        ->and($tests['ephemeral_message']['target'])->toBe('user_and_channel')
        ->and($response->getContent())->not->toContain('xoxb-suite-token');
});

test('an unknown test answers not found and the required targets are validated', function () {
    makeSuiteInstallation();
    $admin = makeSuiteAdmin();

    runSuiteTest($this, $admin, 'does_not_exist')->assertNotFound();
    runSuiteTest($this, $admin, 'direct_message')->assertUnprocessable()->assertJsonValidationErrors('user_id');
    runSuiteTest($this, $admin, 'channel_message', ['channel_id' => 'not-a-channel'])->assertUnprocessable()->assertJsonValidationErrors('channel_id');
});

test('tests are skipped without a workspace, without a scope or without a linked recipient', function () {
    Http::fake();
    $admin = makeSuiteAdmin();

    runSuiteTest($this, $admin, 'bot_identity')->assertOk()->assertJsonPath('status', 'skipped');

    $installation = makeSuiteInstallation(['scopes' => 'chat:write']);
    $unlinked = User::factory()->create();

    runSuiteTest($this, $admin, 'reaction', ['channel_id' => 'C123'])
        ->assertOk()
        ->assertJsonPath('status', 'skipped')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'reactions:write'));

    runSuiteTest($this, $admin, 'direct_message', ['user_id' => $unlinked->id])->assertOk()->assertJsonPath('status', 'skipped');

    Http::assertNothingSent();
});

test('the bot identity test confirms the token belongs to the active workspace', function () {
    makeSuiteInstallation();
    Http::fake(['slack.com/api/auth.test' => Http::response(['ok' => true, 'team_id' => 'T100', 'team' => 'Acme', 'user' => 'workspace_bot'])]);

    runSuiteTest($this, makeSuiteAdmin(), 'bot_identity')
        ->assertOk()
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('steps.0.name', 'auth.test');
});

test('a sample notification uses the real notification layout and links to the message', function () {
    $installation = makeSuiteInstallation();
    $recipient = makeLinkedRecipient($installation);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse()),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/archives/D300/p1700000000000100']),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'mention_notification', ['user_id' => $recipient->id])
        ->assertOk()
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('links.0.url', 'https://acme.slack.com/archives/D300/p1700000000000100');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), 'chat.postMessage')) {
            return false;
        }

        $blocks = $request['blocks'];

        return $request['channel'] === 'U300'
            && str_contains($blocks[0]['text']['text'], '*Ada Admin* mentioned you')
            && $blocks[1]['elements'][0]['text'] === 'Slack notification tests'
            && $blocks[2]['elements'][0]['text']['text'] === 'Open in workspace';
    });
});

test('a sample notification warns when the recipient turned that notification type off for slack', function () {
    $installation = makeSuiteInstallation();
    $recipient = makeLinkedRecipient($installation, ['notification_preferences' => ['assigned_slack' => false]]);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse()),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'assignment_notification', ['user_id' => $recipient->id])
        ->assertOk()
        ->assertJsonPath('status', 'warning');
});

test('the recipient settings test reports silenced notification types without calling slack', function () {
    Http::fake();
    $installation = makeSuiteInstallation();
    $recipient = makeLinkedRecipient($installation, ['notification_preferences' => ['mentioned_slack' => false]]);

    runSuiteTest($this, makeSuiteAdmin(), 'recipient_settings', ['user_id' => $recipient->id])
        ->assertOk()
        ->assertJsonPath('status', 'warning')
        ->assertJsonPath('steps.0.detail', 'Off for mentioned.');

    Http::assertNothingSent();
});

test('the recipient lookup warns when the email belongs to a different slack member', function () {
    $installation = makeSuiteInstallation();
    $recipient = makeLinkedRecipient($installation);

    Http::fake([
        'slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['id' => 'U300', 'real_name' => 'Rita R', 'deleted' => false]]),
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U999']]),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'recipient_lookup', ['user_id' => $recipient->id])
        ->assertOk()
        ->assertJsonPath('status', 'warning');
});

test('the channel message is confirmed through the channel history, and only warns without the history scope', function () {
    makeSuiteInstallation();
    $admin = makeSuiteAdmin();

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse('C123')),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
        'slack.com/api/conversations.history*' => Http::sequence()
            ->push(['ok' => true, 'messages' => [['ts' => '1700000000.000100']]])
            ->push(['ok' => false, 'error' => 'missing_scope']),
    ]);

    runSuiteTest($this, $admin, 'channel_message', ['channel_id' => 'C123'])->assertOk()->assertJsonPath('status', 'passed');
    runSuiteTest($this, $admin, 'channel_message', ['channel_id' => 'C123'])->assertOk()->assertJsonPath('status', 'warning');
});

test('a slack error fails the test with a readable reason and the failed step', function () {
    makeSuiteInstallation();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'])]);

    runSuiteTest($this, makeSuiteAdmin(), 'thread_reply', ['channel_id' => 'C123'])
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('detail', 'The Slack app is not a member of that channel. Invite it to the channel first.')
        ->assertJsonPath('steps.0.status', 'failed')
        ->assertJsonPath('steps.0.detail', 'not_in_channel');
});

test('the ephemeral message explains when the recipient is not in the channel', function () {
    $installation = makeSuiteInstallation();
    $recipient = makeLinkedRecipient($installation);
    Http::fake(['slack.com/api/chat.postEphemeral' => Http::response(['ok' => false, 'error' => 'user_not_in_channel'])]);

    runSuiteTest($this, makeSuiteAdmin(), 'ephemeral_message', ['user_id' => $recipient->id, 'channel_id' => 'C123'])
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'recipient is not a member'));
});

test('the thread reply answers the parent message in its thread', function () {
    makeSuiteInstallation();
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::sequence()
            ->push(slackMessageResponse('C123', '1700000000.000100'))
            ->push(slackMessageResponse('C123', '1700000000.000200')),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'thread_reply', ['channel_id' => 'C123'])->assertOk()->assertJsonPath('status', 'passed');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'chat.postMessage') && ($request['thread_ts'] ?? null) === '1700000000.000100');
});

test('the message update test edits the message it posted', function () {
    makeSuiteInstallation();
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse('C123')),
        'slack.com/api/chat.update' => Http::response(['ok' => true]),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'message_update', ['channel_id' => 'C123'])->assertOk()->assertJsonPath('status', 'passed');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'chat.update') && $request['ts'] === '1700000000.000100' && str_contains($request['text'], 'completed'));
});

test('the reaction test adds a reaction and reads it back', function () {
    makeSuiteInstallation();
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse('C123')),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
        'slack.com/api/reactions.add' => Http::response(['ok' => true]),
        'slack.com/api/reactions.get*' => Http::response(['ok' => true, 'message' => ['reactions' => [['name' => 'white_check_mark', 'count' => 1]]]]),
    ]);

    $steps = runSuiteTest($this, makeSuiteAdmin(), 'reaction', ['channel_id' => 'C123'])
        ->assertOk()
        ->assertJsonPath('status', 'passed')
        ->json('steps');

    expect(array_column($steps, 'name'))->toBe(['chat.postMessage', 'reactions.add', 'reactions.get']);
});

test('the file upload test uses the external upload flow, never the retired files.upload', function () {
    makeSuiteInstallation();
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/abc', 'file_id' => 'F123']),
        'files.slack.com/upload/v1/abc' => Http::response('OK - 120'),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true, 'files' => [['id' => 'F123', 'title' => 'Slack notification test file']]]),
        'slack.com/api/files.info*' => Http::response(['ok' => true, 'file' => ['id' => 'F123', 'name' => 'slack-notification-test.txt', 'size' => 120, 'permalink' => 'https://acme.slack.com/files/F123']]),
    ]);

    runSuiteTest($this, makeSuiteAdmin(), 'file_upload', ['channel_id' => 'C123'])
        ->assertOk()
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('links.0.url', 'https://acme.slack.com/files/F123');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'files.completeUploadExternal')
        && $request['channel_id'] === 'C123'
        && $request['files'][0]['id'] === 'F123');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'files.upload'));
});

test('the scheduled message is scheduled about a minute ahead', function () {
    makeSuiteInstallation();
    $this->freezeTime();
    Http::fake(['slack.com/api/chat.scheduleMessage' => Http::response(['ok' => true, 'scheduled_message_id' => 'Q1', 'post_at' => now()->addMinute()->getTimestamp()])]);

    runSuiteTest($this, makeSuiteAdmin(), 'scheduled_message', ['channel_id' => 'C123'])->assertOk()->assertJsonPath('status', 'passed');

    Http::assertSent(fn (Request $request) => $request['post_at'] === now()->addMinute()->getTimestamp());
});

test('the queued notification goes through the slack message job', function () {
    Queue::fake();
    $installation = makeSuiteInstallation(['is_active' => true]);
    $recipient = makeLinkedRecipient($installation);

    runSuiteTest($this, makeSuiteAdmin(), 'queued_notification', ['user_id' => $recipient->id])->assertOk()->assertJsonPath('status', 'passed');

    Queue::assertPushed(SendSlackMessageJob::class, fn (SendSlackMessageJob $job) => $job->channel === 'U300');
});

test('the app mention test passes once slack delivered an app_mention event', function () {
    makeSuiteInstallation();
    $admin = makeSuiteAdmin();

    runSuiteTest($this, $admin, 'app_mention_event')->assertOk()->assertJsonPath('status', 'warning');

    $body = json_encode(['type' => 'event_callback', 'team_id' => 'T100', 'event' => ['type' => 'app_mention', 'user' => 'U300', 'channel' => 'C123', 'text' => '<@UBOT> hi']]);
    $timestamp = now()->getTimestamp();
    $headers = [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'a1b2c3d4e5f60718293a4b5c6d7e8f90'),
        'Content-Type' => 'application/json',
    ];
    $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();

    runSuiteTest($this, $admin, 'app_mention_event')
        ->assertOk()
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'U300'));
});

test('running a test that sends a message is recorded in the audit log', function () {
    makeSuiteInstallation();
    $admin = makeSuiteAdmin();
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackMessageResponse('C123')),
        'slack.com/api/chat.update' => Http::response(['ok' => true]),
        'slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'permalink' => 'https://acme.slack.com/x']),
    ]);

    runSuiteTest($this, $admin, 'message_update', ['channel_id' => 'C123'])->assertOk();

    expect(AuditLog::query()->where('event', 'slack.notification_test_run')->where('user_id', $admin->id)->exists())->toBeTrue();
});
