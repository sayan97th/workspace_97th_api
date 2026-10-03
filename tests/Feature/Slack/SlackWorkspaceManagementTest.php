<?php

use App\Jobs\SendSlackMessageJob;
use App\Models\SlackAppSetting;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Services\Slack\SlackNotifier;
use App\Services\Slack\SlackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    config([
        'services.slack.client_id' => '111.222',
        'services.slack.client_secret' => 'environmentsecret0001',
        'services.slack.signing_secret' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'services.slack.redirect' => 'https://api.example.com/api/integrations/slack/callback',
        'app.frontend_url' => 'http://frontend.test',
    ]);
});

function connectSlackWorkspace(string $team_id, string $team_name, array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => $team_id,
        'team_name' => $team_name,
        'bot_user_id' => 'UBOT',
        'bot_token' => "xoxb-{$team_id}",
        'scopes' => implode(',', SlackService::BOT_SCOPES),
    ], $overrides));
}

/**
 * @return array<string, string>
 */
function signedSlackHeaders(string $body, ?string $secret = null): array
{
    $timestamp = now()->getTimestamp();
    $secret ??= (string) config('services.slack.signing_secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret),
        'Content-Type' => 'application/json',
    ];
}

function makeWorkspaceOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');

    return $owner;
}

/**
 * Runs "Add to Slack" through the callback, Slack answers for `$team_id`.
 */
function installThroughCallback($test, User $actor, string $team_id, string $team_name, string $display = 'tab'): TestResponse
{
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true, 'access_token' => "xoxb-new-{$team_id}", 'bot_user_id' => 'UBOT', 'app_id' => 'A1',
            'scope' => implode(',', SlackService::BOT_SCOPES), 'team' => ['id' => $team_id, 'name' => $team_name],
        ]),
        'slack.com/api/auth.test' => Http::response(['ok' => true, 'url' => "https://{$team_id}.slack.com/"]),
        'slack.com/api/auth.revoke' => Http::response(['ok' => true]),
        'slack.com/api/users.list*' => Http::response(['ok' => true, 'members' => []]),
    ]);

    $url = $test->actingAs($actor, 'api')->postJson('/api/integrations/slack/install-url', ['display' => $display])->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return $test->get('/api/integrations/slack/callback?code=abc&state='.$query['state']);
}

test('the first workspace becomes active on its own', function () {
    $installation = connectSlackWorkspace('T1', 'Make It Simple');

    expect($installation->fresh()->is_active)->toBeTrue()
        ->and(SlackInstallation::current()->id)->toBe($installation->id);
});

test('adding another workspace keeps the first one and makes the new one active', function () {
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    $owner = makeWorkspaceOwner();

    installThroughCallback($this, $owner, 'T2', '97th Floor')
        ->assertRedirect('http://frontend.test/integrations/slack/complete?purpose=install&workspace=97th+Floor&matched=0&slack=connected');

    expect(SlackInstallation::count())->toBe(2)
        ->and($first->fresh()->is_active)->toBeFalse()
        ->and(SlackInstallation::current()->team_name)->toBe('97th Floor')
        ->and(SlackInstallation::current()->team_url)->toBe('https://T2.slack.com/')
        ->and(SlackInstallation::current()->app_id)->toBe('A1');
});

test('a flow opened in a new tab finishes on the completion page', function () {
    installThroughCallback($this, makeWorkspaceOwner(), 'T2', '97th Floor')
        ->assertRedirectContains('/integrations/slack/complete?purpose=install');

    // An expired state cannot know how the flow was opened, the completion page handles both.
    $this->get('/api/integrations/slack/callback?code=abc&state=forged')
        ->assertRedirect('http://frontend.test/integrations/slack/complete?slack=error&reason=invalid_state');
});

test('administrators list workspaces and switch the active one', function () {
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    $second = connectSlackWorkspace('T2', '97th Floor');
    $owner = makeWorkspaceOwner();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/workspaces')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.team_name', 'Make It Simple')
        ->assertJsonPath('data.0.is_active', true)
        ->assertJsonMissing(['bot_token' => 'xoxb-T1']);

    $this->actingAs($owner, 'api')->postJson("/api/integrations/slack/workspaces/{$second->id}/activate")
        ->assertOk()
        ->assertJsonPath('workspace.team_name', '97th Floor')
        ->assertJsonPath('workspaces.0.team_name', '97th Floor');

    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.workspace_activated', 'user_id' => $owner->id]);
});

test('members cannot manage workspaces or app credentials', function () {
    $installation = connectSlackWorkspace('T1', 'Make It Simple');
    $member = User::factory()->create();
    $member->assignRole('client');

    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/workspaces')->assertForbidden();
    $this->actingAs($member, 'api')->postJson("/api/integrations/slack/workspaces/{$installation->id}/activate")->assertForbidden();
    $this->actingAs($member, 'api')->deleteJson("/api/integrations/slack/workspaces/{$installation->id}")->assertForbidden();
    $this->actingAs($member, 'api')->postJson('/api/integrations/slack/match-members')->assertForbidden();
    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/app')->assertForbidden();
    $this->actingAs($member, 'api')->putJson('/api/integrations/slack/app', ['client_id' => '1.2'])->assertForbidden();
});

test('member links are kept per workspace so switching back needs no new sign in', function () {
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    $second = connectSlackWorkspace('T2', '97th Floor');
    $user = User::factory()->create();
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $first->id, 'slack_user_id' => 'U1']);
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $second->id, 'slack_user_id' => 'U2']);

    expect($user->slackLink()->first()->slack_user_id)->toBe('U1');

    app(SlackService::class)->activate($second);

    expect($user->slackLink()->first()->slack_user_id)->toBe('U2');

    $this->actingAs($user, 'api')->getJson('/api/integrations/slack')
        ->assertJsonPath('current_user_link.slack_user_id', 'U2');
});

test('notifications go through the active workspace', function () {
    Queue::fake();
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    $second = connectSlackWorkspace('T2', '97th Floor');
    $user = User::factory()->create();
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $first->id, 'slack_user_id' => 'U1']);
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $second->id, 'slack_user_id' => 'U2']);
    app(SlackService::class)->activate($second);

    expect(app(SlackNotifier::class)->notifyUser($user, 'Hello'))->toBeTrue();

    Queue::assertPushed(SendSlackMessageJob::class, fn (SendSlackMessageJob $job) => $job->installation_id === $second->id && $job->channel === 'U2');
});

test('a channel from another workspace is not posted to the active one', function () {
    Queue::fake();
    connectSlackWorkspace('T1', 'Make It Simple');
    $notifier = app(SlackNotifier::class);

    expect($notifier->notifyChannel('C1', 'Hello', team_id: 'T2'))->toBeFalse()
        ->and($notifier->notifyChannel('C1', 'Hello', team_id: 'T1'))->toBeTrue()
        ->and($notifier->notifyChannel('C1', 'Hello'))->toBeTrue();
});

test('disconnecting the active workspace hands over to the remaining one', function () {
    Http::fake(['slack.com/api/auth.revoke' => Http::response(['ok' => true])]);
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    $second = connectSlackWorkspace('T2', '97th Floor');
    app(SlackService::class)->activate($second);

    $this->actingAs(makeWorkspaceOwner(), 'api')->deleteJson("/api/integrations/slack/workspaces/{$second->id}")
        ->assertOk()
        ->assertJsonPath('message', '97th Floor was disconnected. Make It Simple is now the active Slack workspace.')
        ->assertJsonPath('workspace.team_name', 'Make It Simple')
        ->assertJsonCount(1, 'workspaces');

    expect($first->fresh()->is_active)->toBeTrue();
});

test('a workspace that removed the app is forgotten even when it is not the active one', function () {
    $first = connectSlackWorkspace('T1', 'Make It Simple');
    connectSlackWorkspace('T2', '97th Floor');
    app(SlackService::class)->activate(SlackInstallation::where('team_id', 'T2')->first());

    $body = json_encode(['type' => 'event_callback', 'team_id' => 'T1', 'event' => ['type' => 'app_uninstalled']]);
    $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars(signedSlackHeaders($body)), $body)->assertOk();

    expect($first->fresh())->toBeNull()
        ->and(SlackInstallation::current()->team_id)->toBe('T2');
});

test('members are matched to slack by email without replacing their own links', function () {
    $installation = connectSlackWorkspace('T1', '97th Floor');
    $amanda = User::factory()->create(['email' => 'Amanda@97thfloor.com']);
    $linked = User::factory()->create(['email' => 'linked@97thfloor.com']);
    $missing = User::factory()->create(['email' => 'missing@97thfloor.com']);
    SlackUserLink::create(['user_id' => $linked->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => 'UOWN']);

    Http::fake([
        'slack.com/api/users.list*' => Http::sequence()
            ->push(['ok' => true, 'members' => [
                ['id' => 'UAMANDA', 'profile' => ['email' => 'amanda@97thfloor.com', 'real_name' => 'Amanda Diaz']],
                ['id' => 'UBOT2', 'is_bot' => true, 'profile' => ['email' => 'missing@97thfloor.com']],
            ], 'response_metadata' => ['next_cursor' => 'page2']])
            ->push(['ok' => true, 'members' => [
                ['id' => 'UGONE', 'deleted' => true, 'profile' => ['email' => 'missing@97thfloor.com']],
                ['id' => 'UOTHER', 'profile' => ['email' => 'linked@97thfloor.com']],
            ]]),
    ]);

    $response = $this->actingAs(makeWorkspaceOwner(), 'api')->postJson('/api/integrations/slack/match-members')->assertOk();

    // The owner who ran the match has no Slack account in this workspace either.
    $response->assertJsonPath('result.matched', 1)->assertJsonPath('result.already_linked', 1)->assertJsonPath('result.unmatched', 2);

    $this->assertDatabaseHas('slack_user_links', ['user_id' => $amanda->id, 'slack_user_id' => 'UAMANDA', 'slack_display_name' => 'Amanda Diaz']);
    $this->assertDatabaseHas('slack_user_links', ['user_id' => $linked->id, 'slack_user_id' => 'UOWN']);
    $this->assertDatabaseMissing('slack_user_links', ['user_id' => $missing->id]);
});

test('matching by email explains a missing permission', function () {
    connectSlackWorkspace('T1', 'Make It Simple', ['scopes' => 'chat:write']);
    Http::fake();

    $this->actingAs(makeWorkspaceOwner(), 'api')->postJson('/api/integrations/slack/match-members')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The Slack app is missing a permission. Use "Reconnect" on the workspace in Administration > Integrations to grant it.');

    Http::assertNothingSent();
});

test('installing a workspace matches members by email right away', function () {
    $amanda = User::factory()->create(['email' => 'amanda@97thfloor.com']);
    $owner = makeWorkspaceOwner();

    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true, 'access_token' => 'xoxb-new', 'scope' => implode(',', SlackService::BOT_SCOPES), 'team' => ['id' => 'T2', 'name' => '97th Floor'],
        ]),
        'slack.com/api/auth.test' => Http::response(['ok' => true, 'url' => 'https://97thfloor.slack.com/']),
        'slack.com/api/users.list*' => Http::response(['ok' => true, 'members' => [['id' => 'UAMANDA', 'profile' => ['email' => 'amanda@97thfloor.com']]]]),
    ]);

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url', ['display' => 'tab'])->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirectContains('matched=1');

    expect($amanda->slackLink()->first()->slack_user_id)->toBe('UAMANDA');
});

test('app credentials saved in administration win over the environment and never leak', function () {
    $owner = makeWorkspaceOwner();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('client_id', '111.222');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', [
        'client_id' => '999.888',
        'client_secret' => 'savedclientsecret0001',
        'signing_secret' => 'f0e1d2c3b4a5968778695a4b3c2d1e0f',
    ])
        ->assertOk()
        ->assertJsonPath('source', 'database')
        ->assertJsonPath('client_id', '999.888')
        ->assertJsonPath('client_secret_hint', '••••0001')
        ->assertJsonMissing(['client_secret' => 'savedclientsecret0001']);

    expect(DB::table('slack_app_settings')->value('client_secret'))->not->toBe('savedclientsecret0001');

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query['client_id'])->toBe('999.888');

    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.app_credentials_updated', 'user_id' => $owner->id]);
});

test('a blank secret keeps the saved one only for the same slack app', function () {
    $owner = makeWorkspaceOwner();
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', ['client_id' => '999.888', 'client_secret' => 'savedclientsecret0001'])->assertOk();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', [
        'client_id' => '999.888',
        'redirect_uri' => 'https://tunnel.example.com/api/integrations/slack/callback',
    ])->assertOk()->assertJsonPath('redirect_uri', 'https://tunnel.example.com/api/integrations/slack/callback');

    expect(SlackAppSetting::current()->client_secret)->toBe('savedclientsecret0001');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', ['client_id' => '555.444'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Enter the client secret of this Slack app.');
});

test('invalid app credentials are rejected', function () {
    $this->actingAs(makeWorkspaceOwner(), 'api')->putJson('/api/integrations/slack/app', [
        'client_id' => 'not-a-client-id',
        'client_secret' => 'has spaces in it!',
        'redirect_uri' => 'https://evil.example/phish',
    ])->assertJsonValidationErrors(['client_id', 'client_secret', 'redirect_uri']);
});

test('removing the saved credentials falls back to the environment', function () {
    $owner = makeWorkspaceOwner();
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', ['client_id' => '999.888', 'client_secret' => 'savedclientsecret0001'])->assertOk();

    $this->actingAs($owner, 'api')->deleteJson('/api/integrations/slack/app')
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('client_id', '111.222');
});

test('events signed with the saved or the environment signing secret are both accepted', function () {
    $owner = makeWorkspaceOwner();
    $saved_secret = 'f0e1d2c3b4a5968778695a4b3c2d1e0f';
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', [
        'client_id' => '999.888', 'client_secret' => 'savedclientsecret0001', 'signing_secret' => $saved_secret,
    ])->assertOk();

    $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc']);
    $send = fn (string $secret) => $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars(signedSlackHeaders($body, $secret)), $body);

    $send($saved_secret)->assertOk()->assertJsonPath('challenge', 'abc');
    $send('a1b2c3d4e5f60718293a4b5c6d7e8f90')->assertOk();
    $send('00000000000000000000000000000000')->assertUnauthorized();
});

test('the manifest carries the redirect url, events url and scopes', function () {
    $manifest = $this->actingAs(makeWorkspaceOwner(), 'api')->getJson('/api/integrations/slack/app')->assertOk()->json('manifest');

    expect($manifest['oauth_config']['redirect_urls'])->toBe(['https://api.example.com/api/integrations/slack/callback'])
        ->and($manifest['oauth_config']['scopes']['bot'])->toBe(SlackService::BOT_SCOPES)
        ->and($manifest['settings']['event_subscriptions']['request_url'])->toBe('https://api.example.com/api/integrations/slack/events');
});
