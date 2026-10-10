<?php

use App\Enums\ExternalService;
use App\Jobs\DeleteCalendarEventsJob;
use App\Jobs\SendConnectedAccountEmailJob;
use App\Jobs\SendEmailJob;
use App\Jobs\SyncCalendarEventJob;
use App\Models\AccountSetting;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemCalendarEvent;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\ExternalAccount;
use App\Models\IntegrationAppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\ExternalAccounts\CalendarEventSyncer;
use App\Services\ExternalAccounts\EmailTriggerPoller;
use App\Services\ExternalAccounts\Mail\GmailMailbox;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const GMAIL_SCOPES = ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send'];
const CALENDAR_SCOPES = ['openid', 'https://www.googleapis.com/auth/calendar.calendarlist.readonly', 'https://www.googleapis.com/auth/calendar.events'];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['app.frontend_url' => 'http://frontend.test', 'app.url' => 'http://localhost:8000']);

    IntegrationAppSetting::create(['provider' => 'google', 'client_id' => 'google-client', 'client_secret' => 'google-secret']);
    IntegrationAppSetting::create(['provider' => 'microsoft', 'client_id' => 'ms-client', 'client_secret' => 'ms-secret']);
});

function integrationMember(): User
{
    $member = User::factory()->create();
    $member->assignRole('client');

    return $member;
}

function externalAccount(User $user, array $overrides = []): ExternalAccount
{
    return ExternalAccount::create(array_merge([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-'.$user->id,
        'email' => 'member@example.com',
        'name' => 'Member',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => now()->addHour(),
        'scopes' => GMAIL_SCOPES,
        'connected_at' => now(),
    ], $overrides));
}

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup}
 */
function integrationBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return [$board, $view, $group];
}

/**
 * @param  array<int, array{type: string, params: array<string, mixed>}>  $actions
 */
function integrationAutomation(WorkspaceNavigationItem $board, BoardView $view, string $trigger_type, array $actions, array $attributes = []): BoardAutomation
{
    return BoardAutomation::create(array_merge([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => $trigger_type,
        'action_type' => $actions[0]['type'],
        'action_params' => $actions[0]['params'],
        'actions' => $actions,
    ], $attributes));
}

function gmailMessage(string $id, string $subject, string $from, string $body, int $timestamp): array
{
    return [
        'id' => $id,
        'internalDate' => (string) ($timestamp * 1000),
        'snippet' => $body,
        'payload' => [
            'mimeType' => 'multipart/alternative',
            'headers' => [['name' => 'Subject', 'value' => $subject], ['name' => 'From', 'value' => $from]],
            'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]]],
        ],
    ];
}

// ── Connecting an account ─────────────────────────────────────────────────────

test('a member gets the google consent url with offline access and the gmail scopes', function () {
    $url = $this->actingAs(integrationMember(), 'api')
        ->postJson('/api/integrations/accounts/url', ['service' => 'gmail', 'display' => 'tab'])
        ->assertOk()
        ->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe('google-client')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/api/integrations/accounts/callback')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['scope'])->toContain('https://www.googleapis.com/auth/gmail.readonly')
        ->and($query['state'])->not->toBeEmpty();
});

test('outlook uses the microsoft consent page of the saved tenant', function () {
    IntegrationAppSetting::query()->where('provider', 'microsoft')->update(['tenant_id' => 'contoso.onmicrosoft.com']);

    $url = $this->actingAs(integrationMember(), 'api')->postJson('/api/integrations/accounts/url', ['service' => 'outlook'])->assertOk()->json('url');

    expect($url)->toStartWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?')
        ->toContain('Mail.Read')
        ->toContain('offline_access');
});

test('the consent url is refused while the provider app is not set up, and respects the integrations permission', function () {
    IntegrationAppSetting::query()->where('provider', 'google')->delete();
    $member = integrationMember();

    $this->actingAs($member, 'api')->postJson('/api/integrations/accounts/url', ['service' => 'google_calendar'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'not_configured');

    AccountSetting::current()->update(['account_permissions' => ['client' => ['use_integrations' => false]]]);
    $this->actingAs($member, 'api')->postJson('/api/integrations/accounts/url', ['service' => 'outlook'])->assertForbidden();
});

test('the callback stores the account with its tokens and reports back to the tab', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => implode(' ', GMAIL_SCOPES)]),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'g-42', 'email' => 'ada@97thfloor.com', 'name' => 'Ada Lovelace']),
    ]);
    $member = integrationMember();
    $url = $this->actingAs($member, 'api')->postJson('/api/integrations/accounts/url', ['service' => 'gmail', 'display' => 'tab'])->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $redirect = $this->get('/api/integrations/accounts/callback?code=abc&state='.$query['state'])->assertRedirect()->headers->get('Location');
    $account = ExternalAccount::query()->sole();

    expect($redirect)->toStartWith('http://frontend.test/integrations/accounts/complete?')
        ->toContain('service=gmail')
        ->toContain("account_id={$account->id}")
        ->toContain('result=connected')
        ->and($account->user_id)->toBe($member->id)
        ->and($account->email)->toBe('ada@97thfloor.com')
        ->and($account->refresh_token)->toBe('new-refresh')
        ->and($account->supports(ExternalService::Gmail))->toBeTrue()
        ->and($account->supports(ExternalService::GoogleCalendar))->toBeFalse();

    // The state is single use.
    $this->get('/api/integrations/accounts/callback?code=abc&state='.$query['state'])->assertRedirectContains('reason=invalid_state');
    $this->assertDatabaseHas('audit_logs', ['event' => 'integrations.account_connected', 'user_id' => $member->id]);
});

test('a consent screen with permissions unchecked is reported instead of leaving a half connected account usable', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600, 'scope' => 'openid email']),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'g-42', 'email' => 'ada@97thfloor.com']),
    ]);
    $member = integrationMember();
    $url = $this->actingAs($member, 'api')->postJson('/api/integrations/accounts/url', ['service' => 'gmail', 'display' => 'tab'])->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $this->get('/api/integrations/accounts/callback?code=abc&state='.$query['state'])->assertRedirectContains('reason=missing_scopes');
    $this->actingAs($member, 'api')->getJson('/api/integrations/accounts?service=gmail')->assertJsonCount(0, 'data');
});

test('a member only lists their own accounts for the service, with how many automations use each', function () {
    $member = integrationMember();
    $gmail = externalAccount($member);
    externalAccount($member, ['provider_user_id' => 'calendar-only', 'scopes' => CALENDAR_SCOPES]);
    externalAccount(integrationMember());
    [$board, $view, $group] = integrationBoard();
    integrationAutomation($board, $view, BoardAutomation::TRIGGER_EMAIL_RECEIVED, [['type' => BoardAutomation::ACTION_CREATE_ITEM, 'params' => ['target_group_id' => $group->id]]], [
        'trigger_config' => ['external_account_id' => $gmail->id],
    ]);

    $this->actingAs($member, 'api')->getJson('/api/integrations/accounts?service=gmail')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $gmail->id)
        ->assertJsonPath('data.0.services', ['gmail'])
        ->assertJsonPath('data.0.automations_count', 1)
        ->assertJsonPath('can_connect', true);
});

test('calendars and disconnect only work on the caller own account', function () {
    Http::fake([
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
            ['id' => 'team@group.calendar.google.com', 'summary' => 'Team'],
            ['id' => 'ada@97thfloor.com', 'summary' => 'Ada', 'primary' => true],
        ]]),
        'oauth2.googleapis.com/revoke' => Http::response([]),
    ]);
    $member = integrationMember();
    $account = externalAccount($member, ['scopes' => CALENDAR_SCOPES]);
    $stranger = integrationMember();

    $this->actingAs($stranger, 'api')->getJson("/api/integrations/accounts/{$account->id}/calendars")->assertNotFound();
    $this->actingAs($stranger, 'api')->deleteJson("/api/integrations/accounts/{$account->id}")->assertNotFound();

    $this->actingAs($member, 'api')->getJson("/api/integrations/accounts/{$account->id}/calendars")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Ada')
        ->assertJsonPath('data.0.is_primary', true)
        ->assertJsonPath('data.1.name', 'Team');

    $this->actingAs($member, 'api')->deleteJson("/api/integrations/accounts/{$account->id}")->assertOk();
    expect(ExternalAccount::count())->toBe(0);
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
});

test('only administrators manage the google and microsoft apps and the secret never comes back', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs(integrationMember(), 'api')->getJson('/api/integrations/apps')->assertForbidden();

    $this->actingAs($admin, 'api')->putJson('/api/integrations/apps/microsoft', ['client_id' => 'new-ms', 'client_secret' => 'super-secret-1234', 'tenant_id' => 'organizations'])
        ->assertOk()
        ->assertJsonPath('client_id', 'new-ms')
        ->assertJsonPath('tenant_id', 'organizations')
        ->assertJsonPath('client_secret_hint', '••••1234')
        ->assertJsonMissingPath('client_secret');

    // A different client id needs its own secret.
    $this->actingAs($admin, 'api')->putJson('/api/integrations/apps/google', ['client_id' => 'another-google'])->assertJsonValidationErrors('client_secret');
    $this->actingAs($admin, 'api')->putJson('/api/integrations/apps/github', ['client_id' => 'x'])->assertNotFound();

    $this->actingAs($admin, 'api')->getJson('/api/integrations/apps')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.provider', 'google');
});

// ── Automations ───────────────────────────────────────────────────────────────

test('an email automation may only read the creator own mail account', function () {
    $member = integrationMember();
    $mine = externalAccount($member);
    $theirs = externalAccount(integrationMember());
    $calendar_only = externalAccount($member, ['provider_user_id' => 'cal', 'scopes' => CALENDAR_SCOPES]);
    [$board, $view, $group] = integrationBoard();

    $payload = fn (int $account_id) => [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_EMAIL_RECEIVED,
        'trigger_config' => ['external_account_id' => $account_id],
        'actions' => [['type' => BoardAutomation::ACTION_CREATE_ITEM, 'params' => ['target_group_id' => $group->id, 'item_name' => '{payload.subject}']]],
    ];

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($theirs->id))->assertJsonValidationErrors('trigger_config.external_account_id');
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($calendar_only->id))->assertJsonValidationErrors('trigger_config.external_account_id');
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($mine->id))->assertCreated();
});

test('the poller turns new gmail emails into items with the email as an update, once each', function () {
    $member = integrationMember();
    $account = externalAccount($member);
    [$board, $view, $group] = integrationBoard();
    $automation = integrationAutomation($board, $view, BoardAutomation::TRIGGER_EMAIL_RECEIVED, [
        ['type' => BoardAutomation::ACTION_CREATE_ITEM, 'params' => ['target_group_id' => $group->id, 'item_name' => '{payload.subject}']],
        ['type' => BoardAutomation::ACTION_POST_UPDATE, 'params' => ['message' => "From {payload.from_name}:\n{payload.body}"]],
    ], ['trigger_config' => ['external_account_id' => $account->id, 'subject_filter' => 'lead'], 'created_by_id' => $member->id, 'created_at' => now()->subHour()]);

    $now = now()->getTimestamp();
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1'], ['id' => 'm2']]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response(gmailMessage('m1', 'New lead: Acme', '"Grace Hopper" <grace@acme.com>', 'Please call us back.', $now - 60)),
        'gmail.googleapis.com/gmail/v1/users/me/messages/m2*' => Http::response(gmailMessage('m2', 'Newsletter', 'news@example.com', 'Weekly news', $now - 30)),
    ]);

    expect(app(EmailTriggerPoller::class)->poll())->toBe(1);

    $item = BoardItem::query()->where('group_id', $group->id)->sole();
    expect($item->name)->toBe('New lead: Acme')
        ->and(BoardItemComment::query()->where('item_id', $item->id)->value('body'))->toContain('Grace Hopper')->toContain('Please call us back.')
        ->and($automation->fresh()->state['email_checked_at'] ?? null)->not->toBeNull();

    // The overlap reads the same email again, it is not imported twice.
    expect(app(EmailTriggerPoller::class)->poll())->toBe(0)
        ->and(BoardItem::query()->where('group_id', $group->id)->count())->toBe(1);
});

test('the poller reads outlook inboxes through microsoft graph', function () {
    $member = integrationMember();
    $account = externalAccount($member, ['provider' => 'microsoft', 'scopes' => ['openid', 'Mail.Read', 'Mail.Send', 'User.Read']]);
    [$board, $view, $group] = integrationBoard();
    integrationAutomation($board, $view, BoardAutomation::TRIGGER_EMAIL_RECEIVED, [
        ['type' => BoardAutomation::ACTION_CREATE_ITEM, 'params' => ['target_group_id' => $group->id, 'item_name' => '{payload.subject}']],
    ], ['trigger_config' => ['external_account_id' => $account->id], 'created_at' => now()->subHour()]);

    Http::fake(['graph.microsoft.com/v1.0/me/mailFolders/inbox/messages*' => Http::response(['value' => [[
        'id' => 'AAMk1',
        'subject' => 'Invoice 204',
        'from' => ['emailAddress' => ['name' => 'Billing', 'address' => 'billing@vendor.com']],
        'receivedDateTime' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
        'body' => ['contentType' => 'text', 'content' => 'Attached.'],
    ]]])]);

    expect(app(EmailTriggerPoller::class)->poll())->toBe(1)
        ->and(BoardItem::query()->where('group_id', $group->id)->value('name'))->toBe('Invoice 204');
    Http::assertSent(fn (HttpRequest $request) => str_contains(urldecode($request->url()), 'receivedDateTime gt '));
});

test('an expired token is refreshed and a revoked account pauses its email automation', function () {
    $member = integrationMember();
    $account = externalAccount($member, ['token_expires_at' => now()->subMinute()]);
    [$board, $view, $group] = integrationBoard();
    $automation = integrationAutomation($board, $view, BoardAutomation::TRIGGER_EMAIL_RECEIVED, [
        ['type' => BoardAutomation::ACTION_CREATE_ITEM, 'params' => ['target_group_id' => $group->id]],
    ], ['trigger_config' => ['external_account_id' => $account->id], 'created_by_id' => $member->id]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'refreshed', 'expires_in' => 3600])
            ->push(['error' => 'invalid_grant'], 400),
        'gmail.googleapis.com/*' => Http::sequence()
            ->push(['messages' => []])
            ->push([], 401),
    ]);

    app(EmailTriggerPoller::class)->poll();
    expect($account->fresh()->access_token)->toBe('refreshed')
        ->and($automation->fresh()->is_enabled)->toBeTrue();

    app(EmailTriggerPoller::class)->poll();
    expect($automation->fresh()->is_enabled)->toBeFalse()
        ->and($automation->fresh()->paused_reason)->toContain('Connect the account again')
        ->and($account->fresh()->last_error)->not->toBeNull();
});

test('send an email goes out from the connected gmail account instead of the app mailer', function () {
    Queue::fake();
    $member = integrationMember();
    $account = externalAccount($member);
    [$board, $view, $group] = integrationBoard();
    integrationAutomation($board, $view, BoardAutomation::TRIGGER_ITEM_CREATED, [[
        'type' => BoardAutomation::ACTION_SEND_EMAIL,
        'params' => ['email_addresses' => ['client@acme.com'], 'subject' => 'New item', 'message' => '{item_name} is ready', 'external_account_id' => $account->id],
    ]]);

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Proposal'])->assertSuccessful();

    Queue::assertPushed(SendConnectedAccountEmailJob::class, fn (SendConnectedAccountEmailJob $job) => $job->external_account_id === $account->id && $job->to === ['client@acme.com'] && str_contains($job->html_body, 'Proposal is ready'));
    Queue::assertNotPushed(SendEmailJob::class);
});

test('the gmail mailbox sends a base64url raw message', function () {
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent-1'])]);
    $account = externalAccount(integrationMember());

    app(GmailMailbox::class)->send($account, ['client@acme.com'], 'Hello', '<p>Hi</p>');

    Http::assertSent(function (HttpRequest $request) {
        $raw = base64_decode(strtr((string) $request['raw'], '-_', '+/'));

        return str_contains($raw, 'To: client@acme.com') && str_contains($raw, 'Content-Type: text/html');
    });
});

test('item created or updated keeps one google calendar event per item and removes it on delete', function () {
    Queue::fake([DeleteCalendarEventsJob::class, SyncCalendarEventJob::class]);
    $member = integrationMember();
    $account = externalAccount($member, ['scopes' => CALENDAR_SCOPES]);
    [$board, $view, $group] = integrationBoard();
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE, 'scope' => BoardColumn::SCOPE_ITEM]);
    $params = ['external_account_id' => $account->id, 'calendar_id' => 'primary', 'calendar_name' => 'Ada', 'date_column_id' => $date->id];

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED_OR_UPDATED,
        'actions' => [['type' => BoardAutomation::ACTION_GOOGLE_CALENDAR_SYNC, 'params' => $params]],
    ])->assertCreated();

    $item_id = $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Kickoff'])->assertSuccessful()->json('data.id') ?? BoardItem::query()->where('group_id', $group->id)->value('id');
    Queue::assertPushed(SyncCalendarEventJob::class, fn (SyncCalendarEventJob $job) => $job->item_id === (int) $item_id);

    // The job itself: creates, then updates the same event.
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-1' => Http::response(['id' => 'evt-1', 'status' => 'confirmed']),
    ]);
    $item = BoardItem::query()->findOrFail($item_id);
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $date->id, 'value' => '2026-11-03']);
    $automation = BoardAutomation::query()->latest('id')->firstOrFail();
    $syncer = app(CalendarEventSyncer::class);

    expect($syncer->sync($automation, $params, $item))->toContain('Created an event')
        ->and($syncer->sync($automation, $params, $item))->toContain('Updated the event');
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && $request['start'] === ['date' => '2026-11-03'] && $request['end'] === ['date' => '2026-11-04'] && $request['summary'] === 'Kickoff');
    expect(BoardItemCalendarEvent::query()->sole()->event_id)->toBe('evt-1');

    $this->actingAs($member, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}")->assertOk();
    Queue::assertPushed(DeleteCalendarEventsJob::class, fn (DeleteCalendarEventsJob $job) => $job->events[0]['event_id'] === 'evt-1');
    expect(BoardItemCalendarEvent::count())->toBe(0);
});

test('a calendar automation needs a google calendar account of the creator and a date column of the table', function () {
    $member = integrationMember();
    $gmail_only = externalAccount($member);
    $calendar = externalAccount($member, ['provider_user_id' => 'cal', 'scopes' => CALENDAR_SCOPES]);
    [$board, $view] = integrationBoard();
    $text = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TEXT, 'scope' => BoardColumn::SCOPE_ITEM]);
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TIMELINE, 'scope' => BoardColumn::SCOPE_ITEM]);

    $payload = fn (int $account_id, int $column_id) => [
        'view_id' => $view->id,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED_OR_UPDATED,
        'actions' => [['type' => BoardAutomation::ACTION_GOOGLE_CALENDAR_SYNC, 'params' => ['external_account_id' => $account_id, 'calendar_id' => 'primary', 'date_column_id' => $column_id]]],
    ];

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($gmail_only->id, $date->id))->assertJsonValidationErrors('actions.0.params.external_account_id');
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($calendar->id, $text->id))->assertJsonValidationErrors('actions.0.params.date_column_id');
    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/automations", $payload($calendar->id, $date->id))->assertCreated();
});
