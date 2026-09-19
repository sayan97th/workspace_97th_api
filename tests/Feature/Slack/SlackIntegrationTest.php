<?php

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Slack\SlackService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    config([
        'services.slack.client_id' => 'client-id',
        'services.slack.client_secret' => 'client-secret',
        'services.slack.redirect' => 'http://localhost/api/integrations/slack/callback',
        'app.frontend_url' => 'http://frontend.test',
    ]);
});

function makeSlackInstallation(array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => 'T100',
        'team_name' => 'Acme',
        'bot_user_id' => 'UBOT',
        'bot_token' => 'xoxb-secret-token',
        'scopes' => 'chat:write',
    ], $overrides));
}

function makeAdminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

test('any signed in user can read the slack status, without the bot token', function () {
    makeSlackInstallation();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->getJson('/api/integrations/slack')->assertOk();

    $response->assertJsonPath('is_configured', true)
        ->assertJsonPath('is_connected', true)
        ->assertJsonPath('can_manage', false)
        ->assertJsonPath('workspace.team_name', 'Acme')
        ->assertJsonPath('current_user_link', null);

    expect($response->getContent())->not->toContain('xoxb-secret-token');
});

test('the bot token is encrypted at rest', function () {
    makeSlackInstallation();

    $raw_value = DB::table('slack_installations')->value('bot_token');

    expect($raw_value)->not->toBe('xoxb-secret-token')
        ->and(SlackInstallation::current()->bot_token)->toBe('xoxb-secret-token');
});

test('only administrators can request the install url', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($client, 'api')->postJson('/api/integrations/slack/install-url')->assertForbidden();

    $response = $this->actingAs(makeAdminUser(), 'api')->postJson('/api/integrations/slack/install-url')->assertOk();

    expect($response->json('url'))
        ->toStartWith('https://slack.com/oauth/v2/authorize?')
        ->toContain('client_id=client-id')
        ->toContain('chat%3Awrite');
});

test('the install url is refused while the server has no slack credentials', function () {
    config(['services.slack.client_id' => null]);

    $this->actingAs(makeAdminUser(), 'api')->postJson('/api/integrations/slack/install-url')->assertStatus(503);
});

test('an administrator can complete the install through the callback', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-new-token',
            'bot_user_id' => 'UBOT',
            'scope' => 'chat:write,channels:read',
            'team' => ['id' => 'T100', 'name' => 'Acme'],
        ]),
    ]);

    $admin = makeAdminUser();
    $state = parse_url($this->actingAs($admin, 'api')->postJson('/api/integrations/slack/install-url')->json('url'), PHP_URL_QUERY);
    parse_str($state, $query);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])
        ->assertRedirect('http://frontend.test/administration?section=integrations&slack=connected');

    $installation = SlackInstallation::current();
    expect($installation->team_name)->toBe('Acme')
        ->and($installation->bot_token)->toBe('xoxb-new-token')
        ->and($installation->installed_by_id)->toBe($admin->id);

    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.connected', 'user_id' => $admin->id]);
});

test('a state value can only be used once', function () {
    $admin = makeAdminUser();
    $slack_service = app(SlackService::class);
    parse_str(parse_url($slack_service->buildInstallUrl($admin), PHP_URL_QUERY), $query);

    Http::fake(['slack.com/api/oauth.v2.access' => Http::response([
        'ok' => true, 'access_token' => 'xoxb-1', 'team' => ['id' => 'T100', 'name' => 'Acme'],
    ])]);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirectContains('slack=connected');
    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirectContains('slack=error');
});

test('an unknown state is rejected without contacting slack', function () {
    Http::fake();

    $this->get('/api/integrations/slack/callback?code=abc&state=forged')
        ->assertRedirectContains('slack=error&reason=invalid_state');

    Http::assertNothingSent();
    expect(SlackInstallation::current())->toBeNull();
});

test('a non administrator cannot finish an install even with a valid state', function () {
    Http::fake();
    $member = User::factory()->create();
    $member->assignRole('client');

    parse_str(parse_url(app(SlackService::class)->buildInstallUrl($member), PHP_URL_QUERY), $query);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])
        ->assertRedirectContains('reason=forbidden');

    Http::assertNothingSent();
    expect(SlackInstallation::current())->toBeNull();
});

test('a member can link their own slack account', function () {
    $installation = makeSlackInstallation();
    Http::fake([
        'slack.com/api/openid.connect.token' => Http::response(['ok' => true, 'access_token' => 'xoxp-user']),
        'slack.com/api/openid.connect.userInfo' => Http::response([
            'ok' => true, 'sub' => 'U777', 'name' => 'Amanda Diaz', 'https://slack.com/team_id' => 'T100',
        ]),
    ]);

    $user = User::factory()->create();
    $link_url = $this->actingAs($user, 'api')->postJson('/api/integrations/slack/link-url')->assertOk()->json('url');
    parse_str(parse_url($link_url, PHP_URL_QUERY), $query);

    expect($query['team'])->toBe('T100')->and($query['scope'])->toBe('openid profile email');

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])
        ->assertRedirect('http://frontend.test/profile?section=notifications&slack=connected');

    $this->assertDatabaseHas('slack_user_links', [
        'user_id' => $user->id,
        'slack_installation_id' => $installation->id,
        'slack_user_id' => 'U777',
        'slack_display_name' => 'Amanda Diaz',
    ]);

    $this->actingAs($user, 'api')->getJson('/api/integrations/slack')
        ->assertJsonPath('current_user_link.slack_user_id', 'U777');
});

test('linking is refused when the slack account belongs to another workspace', function () {
    makeSlackInstallation();
    Http::fake([
        'slack.com/api/openid.connect.token' => Http::response(['ok' => true, 'access_token' => 'xoxp-user']),
        'slack.com/api/openid.connect.userInfo' => Http::response([
            'ok' => true, 'sub' => 'U777', 'https://slack.com/team_id' => 'TOTHER',
        ]),
    ]);

    $user = User::factory()->create();
    parse_str(parse_url(app(SlackService::class)->buildLinkUrl($user), PHP_URL_QUERY), $query);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])
        ->assertRedirectContains('reason=wrong_workspace');

    expect(SlackUserLink::count())->toBe(0);
});

test('a member can unlink their slack account', function () {
    $installation = makeSlackInstallation();
    $user = User::factory()->create();
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => 'U777']);

    $this->actingAs($user, 'api')->deleteJson('/api/integrations/slack/link')
        ->assertOk()
        ->assertJsonPath('current_user_link', null);

    expect(SlackUserLink::count())->toBe(0);
});

test('disconnecting removes the installation and links and switches off slack automations', function () {
    $installation = makeSlackInstallation();
    $user = User::factory()->create();
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => 'U777']);
    Http::fake(['slack.com/api/auth.revoke' => Http::response(['ok' => true])]);

    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $automation = BoardAutomation::create([
        'board_id' => $board->id, 'board_view_id' => $view->id, 'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED, 'trigger_column_id' => null,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C123'],
    ]);

    $this->actingAs(makeAdminUser(), 'api')->deleteJson('/api/integrations/slack')
        ->assertOk()
        ->assertJsonPath('is_connected', false);

    expect(SlackInstallation::count())->toBe(0)
        ->and(SlackUserLink::count())->toBe(0)
        ->and($automation->fresh()->is_enabled)->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'auth.revoke'));
});

test('members cannot disconnect the workspace', function () {
    makeSlackInstallation();
    $member = User::factory()->create();
    $member->assignRole('client');

    $this->actingAs($member, 'api')->deleteJson('/api/integrations/slack')->assertForbidden();

    expect(SlackInstallation::count())->toBe(1);
});

test('channels are listed alphabetically across pages and cached', function () {
    makeSlackInstallation();
    Cache::flush();
    Http::fakeSequence('slack.com/api/conversations.list*')
        ->push(['ok' => true, 'channels' => [['id' => 'C2', 'name' => 'random']], 'response_metadata' => ['next_cursor' => 'page2']])
        ->push(['ok' => true, 'channels' => [['id' => 'C1', 'name' => 'general'], ['id' => 'G1', 'name' => 'secret', 'is_private' => true]], 'response_metadata' => ['next_cursor' => '']]);

    $user = User::factory()->create();

    $first = $this->actingAs($user, 'api')->getJson('/api/integrations/slack/channels')->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/integrations/slack/channels')->assertOk();

    expect(array_column($first->json('data'), 'name'))->toBe(['general', 'random', 'secret'])
        ->and($first->json('data.2.is_private'))->toBeTrue();

    Http::assertSentCount(2);
});

test('the test message goes straight to the linked member and surfaces slack errors', function () {
    $installation = makeSlackInstallation();
    $user = User::factory()->create();
    SlackUserLink::create(['user_id' => $user->id, 'slack_installation_id' => $installation->id, 'slack_user_id' => 'U777']);

    Http::fake(['slack.com/api/chat.postMessage' => Http::sequence()
        ->push(['ok' => true])
        ->push(['ok' => false, 'error' => 'channel_not_found'])]);

    $this->actingAs($user, 'api')->postJson('/api/integrations/slack/link/test')->assertOk();
    Http::assertSent(fn ($request) => $request['channel'] === 'U777' && $request->hasHeader('Authorization', 'Bearer xoxb-secret-token'));

    $this->actingAs($user, 'api')->postJson('/api/integrations/slack/link/test')->assertStatus(422);

    $unlinked = User::factory()->create();
    $this->actingAs($unlinked, 'api')->postJson('/api/integrations/slack/link/test')->assertStatus(422);
});

test('slack automation actions are refused while slack is not connected', function () {
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    $payload = [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C123', 'slack_channel_name' => 'general'],
    ];

    $user = User::factory()->create();
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action_type');

    makeSlackInstallation();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload)->assertCreated();
});

test('a slack channel automation needs a well formed channel id', function () {
    makeSlackInstallation();
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);

    $base = [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_UPDATE_POSTED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
    ];
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $base + ['action_params' => []])
        ->assertJsonValidationErrors('action_params.slack_channel_id');

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $base + ['action_params' => ['slack_channel_id' => 'general']])
        ->assertJsonValidationErrors('action_params.slack_channel_id');
});

test('a column_changed automation cannot watch a computed column', function () {
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);
    $text = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TEXT]);

    $payload = fn (int $column_id) => [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_COLUMN_CHANGED,
        'trigger_column_id' => $column_id,
        'action_type' => BoardAutomation::ACTION_SEND_EMAIL,
        'action_params' => ['notify_user_id' => User::factory()->create()->id],
    ];
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($formula->id))
        ->assertJsonValidationErrors('trigger_column_id');
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($text->id))->assertCreated();
});

test('the callback returns the browser to the page that started the flow', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true, 'access_token' => 'xoxb-1', 'team' => ['id' => 'T100', 'name' => 'Acme'],
        ]),
    ]);

    $admin = makeAdminUser();
    $url = $this->actingAs($admin, 'api')
        ->postJson('/api/integrations/slack/install-url', ['return_path' => '/boards/42?integrate=slack'])
        ->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])
        ->assertRedirect('http://frontend.test/boards/42?integrate=slack&slack=connected');
});

test('a return path that leaves the app is rejected', function (string $bad_path) {
    $this->actingAs(User::factory()->create(), 'api')
        ->postJson('/api/integrations/slack/link-url', ['return_path' => $bad_path])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('return_path');
})->with([
    'absolute url' => 'https://evil.example/phish',
    'protocol relative' => '//evil.example/phish',
    'backslash trick' => '/\\evil.example',
    'no leading slash' => 'evil.example',
]);
