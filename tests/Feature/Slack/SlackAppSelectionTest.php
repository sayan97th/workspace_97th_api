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

test('the environment app is listed first and its secrets never leak', function () {
    $owner = makeSlackAppAdministrator();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')
        ->assertOk()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('active_key', 'environment')
        ->assertJsonPath('app_name', '97th Floor')
        ->assertJsonPath('app_id', 'APRODUCTION')
        ->assertJsonPath('client_id', '2374960174.1001')
        ->assertJsonPath('redirect_uri', 'https://api.97thfloor.example/api/integrations/slack/callback')
        ->assertJsonCount(1, 'apps')
        ->assertJsonPath('apps.0.key', 'environment')
        ->assertJsonPath('apps.0.is_editable', false)
        ->assertJsonPath('apps.0.is_active', true)
        ->assertJsonPath('apps.0.client_secret_hint', '••••et01')
        ->assertJsonPath('apps.0.has_verification_token', true)
        ->assertJsonMissing(['client_secret' => 'productionclientsecret01'])
        ->assertJsonMissing(['verification_token' => 'legacytoken']);

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack')
        ->assertJsonPath('is_configured', true)
        ->assertJsonPath('credentials_source', 'environment')
        ->assertJsonPath('app_name', '97th Floor');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');
});

test('several apps can be saved and the selected one is used', function () {
    $owner = makeSlackAppAdministrator();

    $first = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/app', [
        'label' => 'Make It Simple',
        'client_id' => '2476073539605.2002',
        'client_secret' => 'savedclientsecret0002',
        'signing_secret' => 'c0c1c2c3c4c5c6c7c8c9cacbcccdcecf',
    ])
        ->assertCreated()
        ->assertJsonPath('source', 'environment')
        ->assertJsonPath('apps.1.name', 'Make It Simple')
        ->assertJsonPath('apps.1.is_active', false)
        ->assertJsonPath('apps.1.is_editable', true)
        ->json('apps.1.key');

    $second = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/app', [
        'label' => 'Sandbox',
        'client_id' => '3333.3003',
        'client_secret' => 'savedclientsecret0003',
    ])->assertCreated()->assertJsonCount(3, 'apps')->json('apps.2.key');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => $first])
        ->assertOk()
        ->assertJsonPath('source', 'database')
        ->assertJsonPath('active_key', $first)
        ->assertJsonPath('app_name', 'Make It Simple')
        ->assertJsonPath('apps.0.is_active', false)
        ->assertJsonPath('apps.1.is_active', true);

    expect(installUrlClientId($this, $owner))->toBe('2476073539605.2002');

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => $second])
        ->assertOk()
        ->assertJsonPath('apps.1.is_active', false)
        ->assertJsonPath('apps.2.is_active', true);

    expect(installUrlClientId($this, $owner))->toBe('3333.3003')
        ->and(SlackAppSetting::query()->where('is_active', true)->count())->toBe(1);

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => 'environment'])
        ->assertOk()
        ->assertJsonPath('source', 'environment');

    expect(installUrlClientId($this, $owner))->toBe('2374960174.1001');
    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.app_selected', 'user_id' => $owner->id]);
});

test('switching apps activates the newest workspace installed with that app', function () {
    $owner = makeSlackAppAdministrator();
    $setting = saveSlackAppCredentials(['app_id' => 'ASAVED', 'label' => 'Make It Simple']);

    $environment_workspace = SlackInstallation::create(['team_id' => 'T97', 'team_name' => '97th Floor', 'app_id' => 'APRODUCTION', 'bot_token' => 'xoxb-97']);
    $saved_workspace = SlackInstallation::create(['team_id' => 'TMIS', 'team_name' => 'Make It Simple', 'app_id' => 'ASAVED', 'bot_token' => 'xoxb-mis']);

    expect($environment_workspace->fresh()->is_active)->toBeTrue();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => (string) $setting->id])
        ->assertOk()
        ->assertJsonPath('message', 'Slack now uses the Make It Simple app and the Make It Simple workspace.');

    expect($saved_workspace->fresh()->is_active)->toBeTrue()->and($environment_workspace->fresh()->is_active)->toBeFalse();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/workspaces')
        ->assertJsonPath('data.0.team_id', 'TMIS')
        ->assertJsonPath('data.0.app', ['key' => (string) $setting->id, 'name' => 'Make It Simple'])
        ->assertJsonPath('data.1.app', ['key' => 'environment', 'name' => '97th Floor']);

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => 'environment'])->assertOk();

    expect($environment_workspace->fresh()->is_active)->toBeTrue();
});

test('selecting an app that is not set up is refused', function () {
    $owner = makeSlackAppAdministrator();

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => '999'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'app_not_found');

    config(['services.slack.client_id' => null, 'services.slack.client_secret' => null]);
    saveSlackAppCredentials();

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')->assertJsonPath('source', 'database');
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => 'environment'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'environment_app_missing');
    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => 'other'])->assertJsonValidationErrors(['app']);
});

test('removing the app in use falls back to the environment app', function () {
    $owner = makeSlackAppAdministrator();
    $setting = saveSlackAppCredentials(['is_active' => true, 'label' => 'Make It Simple']);

    $this->actingAs($owner, 'api')->getJson('/api/integrations/slack/app')->assertJsonPath('source', 'database');
    $this->actingAs($owner, 'api')->deleteJson("/api/integrations/slack/app/{$setting->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Make It Simple removed.')
        ->assertJsonPath('source', 'environment')
        ->assertJsonCount(1, 'apps');
});

test('only administrators select the slack app', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')->putJson('/api/integrations/slack/app/active', ['app' => 'environment'])->assertForbidden();
});

test('events signed by any known app are accepted', function () {
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

test('an authorization started with one app cannot finish after switching to another', function () {
    $owner = makeSlackAppAdministrator();
    $setting = saveSlackAppCredentials();
    Http::fake();

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $this->actingAs($owner, 'api')->putJson('/api/integrations/slack/app/active', ['app' => (string) $setting->id])->assertOk();

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirect();

    Http::assertNothingSent();
    expect(SlackInstallation::count())->toBe(0);
});

test('an install with a saved app remembers its app id', function () {
    $owner = makeSlackAppAdministrator();
    $setting = saveSlackAppCredentials(['is_active' => true]);
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(['ok' => true, 'app_id' => 'ANEWAPP', 'access_token' => 'xoxb-new', 'scope' => implode(',', SlackService::BOT_SCOPES), 'team' => ['id' => 'TMIS', 'name' => 'Make It Simple'], 'bot_user_id' => 'UBOT']),
        'slack.com/api/*' => Http::response(['ok' => true, 'members' => []]),
    ]);

    $url = $this->actingAs($owner, 'api')->postJson('/api/integrations/slack/install-url')->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirect();

    expect($setting->fresh()->app_id)->toBe('ANEWAPP')
        ->and(SlackInstallation::first()->app_id)->toBe('ANEWAPP');
});
