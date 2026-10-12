<?php

use App\Models\SlackAppSetting;
use App\Models\SlackInstallation;
use App\Models\User;
use App\Services\Slack\SlackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    config([
        'app.frontend_url' => 'http://frontend.test',
        'services.slack' => [
            'app_name' => '97th Floor',
            'app_id' => 'APRODUCTION',
            'client_id' => '2374960174.1001',
            'client_secret' => 'productionclientsecret01',
            'signing_secret' => 'b0b1b2b3b4b5b6b7b8b9babbbcbdbebf',
            'verification_token' => 'legacytoken',
            'redirect_uri' => 'https://api.97thfloor.example/api/integrations/slack/callback',
        ],
    ]);
});

function makeSlackAppAdministrator(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');

    return $owner;
}

function installUrlClientId(object $test, $owner): string
{
    $url = $test->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    return $query['client_id'];
}

test('the production app comes from the environment and its secrets never leak', function () {
    $owner = makeSlackAppAdministrator();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('app_name', '97th Floor')
        ->assertJsonPath('app_id', 'APRODUCTION')
        ->assertJsonPath('client_id', '2374960174.1001')
        ->assertJsonPath('redirect_uri', 'https://api.97thfloor.example/api/integrations/slack/callback')
        ->assertJsonPath('environment_app.is_configured', true)
        ->assertJsonPath('environment_app.is_active', true)
        ->assertJsonPath('environment_app.client_secret_hint', '••••et01')
        ->assertJsonPath('environment_app.has_verification_token', true)
        ->assertJsonPath('testing_app', null)
        ->assertJsonMissing(['client_secret' => 'productionclientsecret01'])
        ->assertJsonMissing(['verification_token' => 'legacytoken']);

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack')
        ->assertJsonPath('is_configured', true)
        ->assertJsonPath('credentials_source', 'environment')
        ->assertJsonPath('app_name', '97th Floor');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');
});

test('saving a testing app keeps production in use until an administrator switches', function () {
    $owner = makeSlackAppAdministrator();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app', [
        'label' => 'Make It Simple',
        'client_id' => '2476073539605.2002',
        'client_secret' => 'testingclientsecret0002',
        'signing_secret' => 'c0c1c2c3c4c5c6c7c8c9cacbcccdcecf',
    ])
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('testing_app.name', 'Make It Simple')
        ->assertJsonPath('testing_app.is_active', false)
        ->assertJsonPath('testing_app.client_id', '2476073539605.2002');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'database'])
        ->assertOk()
        ->assertJsonPath('source', 'database')
        ->assertJsonPath('app_name', 'Make It Simple')
        ->assertJsonPath('client_id', '2476073539605.2002')
        ->assertJsonPath('environment_app.is_active', false)
        ->assertJsonPath('testing_app.is_active', true);

    expect(installUrlClientId($this, $owner))->toBe('2476073539605.2002');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'environment'])
        ->assertOk()
        ->assertJsonPath('source', 'environment');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');
    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.app_selected', 'user_id' => $owner->id]);
});

test('switching apps activates the newest workspace installed with that app', function () {
    $owner = makeSlackAppAdministrator();
    saveSlackAppCredentials(['app_id' => 'ATESTING', 'label' => 'Make It Simple']);

    $production = SlackInstallation::create(['team_id' => 'T97', 'team_name' => '97th Floor', 'app_id' => 'APRODUCTION', 'bot_token' => 'xoxb-97']);
    $testing = SlackInstallation::create(['team_id' => 'TMIS', 'team_name' => 'Make It Simple', 'app_id' => 'ATESTING', 'bot_token' => 'xoxb-mis']);

    expect($production->fresh()->is_active)->toBeTrue();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'database'])
        ->assertOk()
        ->assertJsonPath('message', 'Slack now uses the Make It Simple app and the Make It Simple workspace.');

    expect($testing->fresh()->is_active)->toBeTrue()->and($production->fresh()->is_active)->toBeFalse();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/workspaces')
        ->assertJsonPath('data.0.team_id', 'TMIS')
        ->assertJsonPath('data.0.app_source', 'database')
        ->assertJsonPath('data.1.app_source', 'environment');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'environment'])->assertOk();

    expect($production->fresh()->is_active)->toBeTrue();
});

test('switching to an app that is not set up is refused', function () {
    $owner = makeSlackAppAdministrator();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'database'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'testing_app_missing');

    config(['services.slack.client_id' => null, 'services.slack.client_secret' => null]);
    saveSlackAppCredentials();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')->assertJsonPath('source', 'database');
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'environment'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'environment_app_missing');
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'other'])->assertJsonValidationErrors(['source']);
});

test('removing the testing app falls back to the production app', function () {
    $owner = makeSlackAppAdministrator();
    saveSlackAppCredentials(['is_active' => true]);

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')->assertJsonPath('source', 'database');
    $this->actingAs($owner, 'api')->deleteJson('/api/integrations/slack/app')
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('testing_app', null);
});

test('only administrators switch the slack app', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'environment'])->assertForbidden();
});

test('events signed by either app are accepted', function () {
    saveSlackAppCredentials(['signing_secret' => 'c0c1c2c3c4c5c6c7c8c9cacbcccdcecf']);
    $body = json_encode(['type' => 'url_verification', 'challenge' => 'abc']);

    $send = function (string $secret) use ($body) {
        $timestamp = now()->getTimestamp();
        $headers = [
            'X-Slack-Request-Timestamp' => (string) $timestamp,
            'X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret),
            'Content-Type' => 'application/json',
        ];

        return $this->call('POST', '/api/integrations/slack/events', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    };

    $send('b0b1b2b3b4b5b6b7b8b9babbbcbdbebf')->assertOk()->assertJsonPath('challenge', 'abc');
    $send('c0c1c2c3c4c5c6c7c8c9cacbcccdcecf')->assertOk();
    $send('d0d1d2d3d4d5d6d7d8d9dadbdcdddedf')->assertUnauthorized();
});

test('an authorization started with one app cannot finish after switching to the other', function () {
    $owner = makeSlackAppAdministrator();
    saveSlackAppCredentials();
    Http::fake();

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['source' => 'database'])->assertOk();

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirect();

    Http::assertNothingSent();
    expect(SlackInstallation::count())->toBe(0);
});

test('an install with the testing app remembers its app id', function () {
    $owner = makeSlackAppAdministrator();
    saveSlackAppCredentials(['is_active' => true]);
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(['ok' => true, 'app_id' => 'ANEWTEST', 'access_token' => 'xoxb-new', 'scope' => implode(',', SlackService::BOT_SCOPES), 'team' => ['id' => 'TMIS', 'name' => 'Make It Simple'], 'bot_user_id' => 'UBOT']),
        'slack.com/api/*' => Http::response(['ok' => true, 'members' => []]),
    ]);

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirect();

    expect(SlackAppSetting::current()->app_id)->toBe('ANEWTEST')
        ->and(SlackInstallation::first()->app_id)->toBe('ANEWTEST');
});
