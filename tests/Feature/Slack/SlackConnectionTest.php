<?php

use App\Jobs\SendSlackMessageJob;
use App\Models\AccountSetting;
use App\Models\BoardAutomation;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\SlackAppSetting;
use App\Models\SlackConnection;
use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    saveSlackAppCredentials(['client_id' => 'client-id', 'redirect_uri' => 'http://localhost:8000/api/integrations/slack/callback']);
    config(['app.frontend_url' => 'http://frontend.test']);
});

function connectionMember(): User
{
    $member = User::factory()->create();
    $member->assignRole('client');

    return $member;
}

function connectionInstallation(array $overrides = []): SlackInstallation
{
    return SlackInstallation::create(array_merge([
        'team_id' => 'T100',
        'team_name' => 'Acme',
        'bot_token' => 'xoxb-acme',
        'scopes' => 'chat:write,channels:read,users:read',
    ], $overrides));
}

function fakeSlackConnectionExchange(string $team_id = 'T200', string $team_name = '97th Floor'): void
{
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-member-token',
            'bot_user_id' => 'UBOT',
            'scope' => 'chat:write,channels:read,users:read',
            'team' => ['id' => $team_id, 'name' => $team_name],
            'authed_user' => ['id' => 'U900'],
        ]),
        'slack.com/api/team.info*' => Http::response(['ok' => true, 'team' => ['url' => 'https://97thfloor.slack.com/']]),
        'slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['name' => 'amanda', 'real_name' => 'Amanda Diaz', 'profile' => ['display_name' => '']]]),
    ]);
}

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView}
 */
function connectionBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return [$board, $view];
}

/**
 * @return array<string, string>
 */
function startConnection(mixed $test, User $user): array
{
    $url = $test->actingAs($user, 'api')->postJson('/api/integrations/slack/connections/url', ['display' => 'tab'])->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

test('any member gets an add to slack url for their own connection, even with a plain http redirect url', function () {
    $query = startConnection($this, connectionMember());

    expect($query['client_id'])->toBe('client-id')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/api/integrations/slack/callback')
        ->and($query['scope'])->toContain('chat:write')
        ->and($query['state'])->not->toBeEmpty();
});

test('the connect url is refused while the slack app is not set up', function () {
    SlackAppSetting::query()->delete();

    $this->actingAs(connectionMember(), 'api')->postJson('/api/integrations/slack/connections/url')
        ->assertStatus(503)
        ->assertJsonPath('code', 'not_configured');
});

test('the connect url respects the integrations permission', function () {
    AccountSetting::current()->update(['account_permissions' => ['client' => ['use_integrations' => false]]]);

    $this->actingAs(connectionMember(), 'api')->postJson('/api/integrations/slack/connections/url')->assertForbidden();
});

test('a member completes a connection through the callback and is linked in that workspace', function () {
    fakeSlackConnectionExchange();
    $member = connectionMember();
    $query = startConnection($this, $member);

    $redirect = $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirect()->headers->get('Location');
    $connection = SlackConnection::query()->sole();

    expect($redirect)->toStartWith('http://frontend.test/integrations/slack/complete?')
        ->toContain('purpose=connect')
        ->toContain("connection_id={$connection->id}")
        ->toContain('slack=connected')
        ->and($connection->user_id)->toBe($member->id)
        ->and($connection->slack_user_id)->toBe('U900')
        ->and($connection->slack_user_name)->toBe('Amanda Diaz')
        ->and($connection->installation->team_name)->toBe('97th Floor')
        // The first workspace ever connected becomes the active one.
        ->and($connection->installation->is_active)->toBeTrue()
        ->and(SlackUserLink::query()->where('user_id', $member->id)->value('slack_user_id'))->toBe('U900');

    $this->assertDatabaseHas('audit_logs', ['event' => 'slack.account_connected', 'user_id' => $member->id]);
});

test('a member connection never changes the active workspace or who installed an existing one', function () {
    $admin = User::factory()->create();
    $active = connectionInstallation(['installed_by_id' => $admin->id]);
    $other = connectionInstallation(['team_id' => 'T200', 'team_name' => 'Old name', 'installed_by_id' => $admin->id, 'is_active' => false]);

    fakeSlackConnectionExchange();
    $member = connectionMember();
    $query = startConnection($this, $member);
    $this->get('/api/integrations/slack/callback?code=abc&state='.$query['state'])->assertRedirectContains('slack=connected');

    expect($active->fresh()->is_active)->toBeTrue()
        ->and($other->fresh()->is_active)->toBeFalse()
        ->and($other->fresh()->installed_by_id)->toBe($admin->id)
        ->and($other->fresh()->team_name)->toBe('97th Floor')
        ->and($other->fresh()->bot_token)->toBe('xoxb-member-token');
});

test('a member only lists their own connections, with how many automations use each', function () {
    $installation = connectionInstallation();
    $member = connectionMember();
    $mine = SlackConnection::create(['user_id' => $member->id, 'slack_installation_id' => $installation->id, 'slack_user_name' => 'Amanda', 'connected_at' => now()]);
    SlackConnection::create(['user_id' => connectionMember()->id, 'slack_installation_id' => $installation->id, 'connected_at' => now()]);

    [$board, $view] = connectionBoard();
    BoardAutomation::create([
        'board_id' => $board->id, 'board_view_id' => $view->id, 'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => ['slack_channel_id' => 'C123', 'slack_connection_id' => $mine->id],
        'actions' => [['type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL, 'params' => ['slack_channel_id' => 'C123', 'slack_connection_id' => $mine->id]]],
    ]);

    $this->actingAs($member, 'api')->getJson('/api/integrations/slack/connections')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.team_name', 'Acme')
        ->assertJsonPath('data.0.is_active_workspace', true)
        ->assertJsonPath('data.0.automations_count', 1)
        ->assertJsonPath('can_connect', true);
});

test('channels and disconnect only work on the caller own connection', function () {
    Http::fake(['slack.com/api/conversations.list*' => Http::response(['ok' => true, 'channels' => [['id' => 'C1', 'name' => 'palomar']], 'response_metadata' => ['next_cursor' => '']])]);
    $installation = connectionInstallation();
    $member = connectionMember();
    $connection = SlackConnection::create(['user_id' => $member->id, 'slack_installation_id' => $installation->id, 'connected_at' => now()]);
    $stranger = connectionMember();

    $this->actingAs($stranger, 'api')->getJson("/api/integrations/slack/connections/{$connection->id}/channels")->assertNotFound();
    $this->actingAs($stranger, 'api')->deleteJson("/api/integrations/slack/connections/{$connection->id}")->assertNotFound();

    $this->actingAs($member, 'api')->getJson("/api/integrations/slack/connections/{$connection->id}/channels")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'palomar');

    $this->actingAs($member, 'api')->deleteJson("/api/integrations/slack/connections/{$connection->id}")->assertOk();
    expect(SlackConnection::count())->toBe(0)
        ->and($installation->fresh())->not->toBeNull();
});

test('a slack channel automation may only use the creator own connection and a channel of its workspace', function () {
    $installation = connectionInstallation();
    $member = connectionMember();
    $mine = SlackConnection::create(['user_id' => $member->id, 'slack_installation_id' => $installation->id, 'connected_at' => now()]);
    $theirs = SlackConnection::create(['user_id' => connectionMember()->id, 'slack_installation_id' => $installation->id, 'connected_at' => now()]);
    [$board, $view] = connectionBoard();

    $payload = fn (array $params) => [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'actions' => [['type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL, 'params' => ['slack_channel_id' => 'C123', 'slack_channel_name' => 'palomar', ...$params]]],
    ];

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload(['slack_connection_id' => $theirs->id]))
        ->assertJsonValidationErrors('actions.0.params.slack_connection_id');

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload(['slack_connection_id' => $mine->id, 'slack_team_id' => 'T999']))
        ->assertJsonValidationErrors('actions.0.params.slack_channel_id');

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload(['slack_connection_id' => $mine->id, 'slack_team_id' => 'T100']))
        ->assertCreated();
});

test('a connection automation posts through its own workspace while another one is active', function () {
    Queue::fake();
    connectionInstallation();
    $other = connectionInstallation(['team_id' => 'T200', 'team_name' => '97th Floor', 'bot_token' => 'xoxb-97th', 'is_active' => false]);
    $member = connectionMember();
    $connection = SlackConnection::create(['user_id' => $member->id, 'slack_installation_id' => $other->id, 'connected_at' => now()]);
    [$board, $view] = connectionBoard();

    $params = ['slack_channel_id' => 'C777', 'slack_channel_name' => 'palomar', 'slack_team_id' => 'T200', 'slack_connection_id' => $connection->id];
    BoardAutomation::create([
        'board_id' => $board->id, 'board_view_id' => $view->id, 'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL,
        'action_params' => $params,
        'actions' => [['type' => BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL, 'params' => $params]],
    ]);

    $group_id = BoardGroup::query()->where('board_view_id', $view->id)->value('id');
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group_id, 'name' => 'New lead'])->assertSuccessful();

    Queue::assertPushed(SendSlackMessageJob::class, fn (SendSlackMessageJob $job) => $job->installation_id === $other->id && $job->channel === 'C777');
    expect($connection->fresh()->last_used_at)->not->toBeNull();

    // Once the member disconnects that account the automation reports why it stopped posting.
    $connection->delete();
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group_id, 'name' => 'Second lead'])->assertSuccessful();

    Queue::assertPushed(SendSlackMessageJob::class, 1);
    $this->assertDatabaseHas('board_automation_run_logs', ['status' => 'failed']);
});
