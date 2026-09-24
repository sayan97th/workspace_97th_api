<?php

use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    config([
        'services.slack.client_id' => '1234.5678',
        'services.slack.client_secret' => 'client-secret',
        'services.slack.signing_secret' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'services.slack.redirect' => 'https://api.example.com/api/integrations/slack/callback',
    ]);
});

function makeDiagnosticsInstallation(array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => 'T100',
        'team_name' => 'Acme',
        'bot_user_id' => 'UBOT',
        'bot_token' => 'xoxb-secret-token',
        'scopes' => 'chat:write,chat:write.public,channels:read,groups:read',
    ], $overrides));
}

function makeDiagnosticsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

/**
 * @return array<string, string>
 */
function signSlackRequest(string $body, ?int $timestamp = null, ?string $secret = null): array
{
    $timestamp ??= now()->getTimestamp();
    $secret ??= (string) config('services.slack.signing_secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret),
        'Content-Type' => 'application/json',
    ];
}

function checkStatus(array $checks, string $key): ?string
{
    return collect($checks)->firstWhere('key', $key)['status'] ?? null;
}

test('only administrators can run the slack diagnostics', function () {
    $member = User::factory()->create();
    $member->assignRole('client');

    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/diagnostics')->assertForbidden();
    $this->actingAs(makeDiagnosticsAdmin(), 'api')->getJson('/api/integrations/slack/diagnostics')->assertOk();
});

test('diagnostics report missing credentials and skip the checks that depend on them', function () {
    config(['services.slack.client_id' => null, 'services.slack.client_secret' => null, 'services.slack.signing_secret' => null]);
    Http::fake();

    $checks = $this->actingAs(makeDiagnosticsAdmin(), 'api')->getJson('/api/integrations/slack/diagnostics')->assertOk()->json('checks');

    expect(checkStatus($checks, 'credentials'))->toBe('failed')
        ->and(checkStatus($checks, 'signing_secret'))->toBe('failed')
        ->and(checkStatus($checks, 'workspace'))->toBe('failed')
        ->and(checkStatus($checks, 'bot_token'))->toBe('skipped')
        ->and(checkStatus($checks, 'channels'))->toBe('skipped');

    Http::assertNothingSent();
});

test('diagnostics pass end to end with a healthy installation, without leaking secrets', function () {
    $installation = makeDiagnosticsInstallation();
    $admin = makeDiagnosticsAdmin();
    SlackUserLink::create([
        'user_id' => $admin->id,
        'slack_installation_id' => $installation->id,
        'slack_user_id' => 'U200',
        'slack_display_name' => 'Ada',
        'linked_at' => now(),
    ]);

    Http::fake([
        'slack.com/api/auth.test' => Http::response(['ok' => true, 'team_id' => 'T100', 'team' => 'Acme', 'user' => 'workspace_bot']),
        'slack.com/api/conversations.list*' => Http::response(['ok' => true, 'channels' => [['id' => 'C1', 'name' => 'general']]]),
    ]);

    $response = $this->actingAs($admin, 'api')->getJson('/api/integrations/slack/diagnostics')->assertOk();
    $checks = $response->json('checks');

    expect(checkStatus($checks, 'credentials'))->toBe('passed')
        ->and(checkStatus($checks, 'redirect_uri'))->toBe('passed')
        ->and(checkStatus($checks, 'workspace'))->toBe('passed')
        ->and(checkStatus($checks, 'bot_token'))->toBe('passed')
        ->and(checkStatus($checks, 'bot_scopes'))->toBe('passed')
        ->and(checkStatus($checks, 'channels'))->toBe('passed')
        ->and(checkStatus($checks, 'personal_link'))->toBe('passed')
        ->and($response->json('app.events_url'))->toBe('https://api.example.com/api/integrations/slack/events')
        ->and($response->getContent())->not->toContain('xoxb-secret-token')
        ->not->toContain('client-secret')
        ->not->toContain('a1b2c3d4e5f60718293a4b5c6d7e8f90');
});

test('diagnostics flag a revoked bot token, missing scopes and a plain http redirect url', function () {
    config(['services.slack.redirect' => 'http://localhost:8000/api/integrations/slack/callback']);
    makeDiagnosticsInstallation(['scopes' => 'chat:write']);

    Http::fake(['slack.com/api/auth.test' => Http::response(['ok' => false, 'error' => 'token_revoked'])]);

    $checks = $this->actingAs(makeDiagnosticsAdmin(), 'api')->getJson('/api/integrations/slack/diagnostics')->assertOk()->json('checks');

    expect(checkStatus($checks, 'redirect_uri'))->toBe('warning')
        ->and(checkStatus($checks, 'bot_token'))->toBe('failed')
        ->and(checkStatus($checks, 'bot_scopes'))->toBe('failed')
        ->and(checkStatus($checks, 'channels'))->toBe('skipped');
});

test('an administrator can post a test message to a channel', function () {
    makeDiagnosticsInstallation();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    $this->actingAs(makeDiagnosticsAdmin(), 'api')
        ->postJson('/api/integrations/slack/diagnostics/channel-test', ['channel_id' => 'C0123ABCD'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C0123ABCD'
        && $request->hasHeader('Authorization', 'Bearer xoxb-secret-token'));
});

test('the channel test reports the real slack error and validates the channel id', function () {
    makeDiagnosticsInstallation();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'])]);
    $admin = makeDiagnosticsAdmin();

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/channel-test', ['channel_id' => 'C0123ABCD'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The Slack app is not a member of that channel. Invite it to the channel first.');

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/channel-test', ['channel_id' => '#general'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');
});

test('the events endpoint answers the url verification challenge and records the signed request', function () {
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'challenge-value']);

    $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars(signSlackRequest($body)), $body)
        ->assertOk()
        ->assertJsonPath('challenge', 'challenge-value');

    Http::fake();
    $checks = $this->actingAs(makeDiagnosticsAdmin(), 'api')->getJson('/api/integrations/slack/diagnostics')->json('checks');

    expect(checkStatus($checks, 'signing_secret'))->toBe('passed');
});

test('the events endpoint rejects bad, missing and stale signatures', function () {
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'challenge-value']);
    $send = fn (array $headers) => $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars($headers), $body);

    $send(signSlackRequest($body, secret: 'wrong-secret'))->assertUnauthorized();
    $send(['Content-Type' => 'application/json'])->assertUnauthorized();
    $send(signSlackRequest($body, now()->subMinutes(10)->getTimestamp()))->assertUnauthorized();
});

test('an app_uninstalled event removes the installation', function () {
    makeDiagnosticsInstallation();
    $body = json_encode(['type' => 'event_callback', 'team_id' => 'T100', 'event' => ['type' => 'app_uninstalled']]);

    $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars(signSlackRequest($body)), $body)
        ->assertOk();

    expect(SlackInstallation::current())->toBeNull();
});

function linkDiagnosticsMember(SlackInstallation $installation, array $user_attributes = [], string $slack_user_id = 'U200'): User
{
    $member = User::factory()->create($user_attributes);

    SlackUserLink::create([
        'user_id' => $member->id,
        'slack_installation_id' => $installation->id,
        'slack_user_id' => $slack_user_id,
        'slack_display_name' => 'member.slack',
        'linked_at' => now(),
    ]);

    return $member;
}

test('the recipient list only includes active members linked to the current workspace', function () {
    $installation = makeDiagnosticsInstallation();
    $linked = linkDiagnosticsMember($installation, ['first_name' => 'Amanda']);
    $deactivated = linkDiagnosticsMember($installation, ['first_name' => 'Zed'], 'U300');
    $deactivated->delete();
    User::factory()->create();

    $response = $this->actingAs(makeDiagnosticsAdmin(), 'api')
        ->getJson('/api/integrations/slack/diagnostics/recipients')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0'))
        ->user_id->toBe($linked->id)
        ->slack_user_id->toBe('U200')
        ->not->toHaveKey('bot_token');
});

test('only administrators can list recipients and send a user notification', function () {
    $installation = makeDiagnosticsInstallation();
    $member = linkDiagnosticsMember($installation);

    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/diagnostics/recipients')->assertForbidden();
    $this->actingAs($member, 'api')
        ->postJson('/api/integrations/slack/diagnostics/user-test', ['user_id' => $member->id, 'message' => 'Hello'])
        ->assertForbidden();
});

test('an administrator can send a slack notification to a linked member', function () {
    $installation = makeDiagnosticsInstallation();
    $member = linkDiagnosticsMember($installation);
    $admin = makeDiagnosticsAdmin();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/user-test', ['user_id' => $member->id, 'message' => 'Review <!channel> & ship'])
        ->assertOk()
        ->assertJsonPath('message', "Notification sent to {$member->full_name}. Ask them to check Slack.");

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'U200'
        && $request->hasHeader('Authorization', 'Bearer xoxb-secret-token')
        && str_contains(json_encode($request['blocks']), 'Review &lt;!channel&gt; &amp; ship'));

    $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'slack.test_notification_sent']);
});

test('the user notification validates input and reports unlinked members and slack errors', function () {
    $installation = makeDiagnosticsInstallation();
    $admin = makeDiagnosticsAdmin();
    $unlinked = User::factory()->create();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/user-test', ['user_id' => 999999, 'message' => str_repeat('a', 1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id', 'message']);

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/user-test', ['user_id' => $unlinked->id, 'message' => 'Hello'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'That member has not linked their Slack account yet. Ask them to use "Connect my Slack" first.');

    $member = linkDiagnosticsMember($installation);

    $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/diagnostics/user-test', ['user_id' => $member->id, 'message' => 'Hello'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Slack could not find where to send that message.');
});
