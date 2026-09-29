<?php

use App\Jobs\Automations\SendAutomationWebhookJob;
use App\Jobs\SendEmailJob;
use App\Models\AccountAutomationTemplate;
use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function roundTwoBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Sprint 1']);
    $status = roundTwoColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [
        ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
        ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
    ]]);

    return [$board, $view, $group, $status];
}

function roundTwoColumn(WorkspaceNavigationItem $board, BoardView $view, string $type, string $label, array $config = [], string $scope = BoardColumn::SCOPE_ITEM): BoardColumn
{
    return BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $type, 'label' => $label, 'scope' => $scope, 'config' => $config ?: null]);
}

function roundTwoAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
{
    $actions = $attributes['actions'];

    return BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'action_type' => $actions[0]['type'],
        'action_params' => $actions[0]['params'],
        ...$attributes,
    ]);
}

function roundTwoSetValue(User $actor, WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, mixed $value): void
{
    test()->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => $value],
    ])->assertOk();
}

function roundTwoValue(BoardItem $item, BoardColumn $column): mixed
{
    return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
}

test('once every subitem is done the parent item status changes', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $subitem_status = roundTwoColumn($board, $view, BoardColumn::TYPE_STATUS, 'Sub status', ['options' => [['id' => 'sub_done', 'label' => 'Done', 'color' => '#00c875']]], BoardColumn::SCOPE_SUBITEM);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $first = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'A', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'B', 'position' => 1]);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ALL_SUBITEMS_STATUS,
        'trigger_column_id' => $subitem_status->id,
        'trigger_value' => 'sub_done',
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']]],
    ]);

    roundTwoSetValue($actor, $board, $first, $subitem_status, 'sub_done');
    expect(roundTwoValue($parent, $status))->toBeNull();

    roundTwoSetValue($actor, $board, $second, $subitem_status, 'sub_done');
    expect(roundTwoValue($parent, $status))->toBe('done');
});

test('once every item of a group is done the group is archived and a new one created', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $items = collect(['One', 'Two'])->map(fn ($name, $index) => $board->items()->create(['group_id' => $group->id, 'name' => $name, 'position' => $index]));

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ALL_GROUP_ITEMS_STATUS,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [
            ['type' => 'create_group', 'params' => ['group_name' => 'Sprint week {week}', 'position' => 'top']],
            ['type' => 'archive_group', 'params' => ['from_item_group' => true]],
        ],
    ]);

    roundTwoSetValue($actor, $board, $items[0], $status, 'done');
    expect($group->fresh()->is_archived)->toBeFalse();

    roundTwoSetValue($actor, $board, $items[1], $status, 'done');
    expect($group->fresh()->is_archived)->toBeTrue();
    expect(BoardGroup::where('board_view_id', $view->id)->where('name', 'like', 'Sprint week %')->where('position', 0)->exists())->toBeTrue();
});

test('date actions push a timeline by months, keep a date after another and add the days of a number column', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $start = roundTwoColumn($board, $view, BoardColumn::TYPE_DATE, 'Start');
    $due = roundTwoColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $timeline = roundTwoColumn($board, $view, BoardColumn::TYPE_TIMELINE, 'Timeline');
    $days = roundTwoColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Days');
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Launch', 'position' => 0]);
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $timeline->id, 'value' => ['start' => '2026-01-31', 'end' => '2026-02-10']]);
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $days->id, 'value' => 4]);
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $due->id, 'value' => '2026-10-02']);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_DATE_CHANGED,
        'trigger_column_id' => $start->id,
        'actions' => [
            ['type' => 'ensure_date_after', 'params' => ['target_column_id' => $due->id, 'source_column_id' => $start->id, 'gap_days' => 1]],
            ['type' => 'shift_date', 'params' => ['target_column_id' => $timeline->id, 'amount' => 1, 'unit' => 'months']],
        ],
    ]);
    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'set_date_from_column', 'params' => ['target_column_id' => $due->id, 'source_column_id' => $start->id, 'number_column_id' => $days->id]]],
    ]);

    roundTwoSetValue($actor, $board, $item, $start, '2026-10-05');
    expect(roundTwoValue($item, $due))->toBe('2026-10-06');
    expect(roundTwoValue($item, $timeline))->toBe(['start' => '2026-02-28', 'end' => '2026-03-10']);

    roundTwoSetValue($actor, $board, $item, $status, 'done');
    expect(roundTwoValue($item, $due))->toBe('2026-10-09');
});

test('a scheduled item scan acts only on the items that pass its conditions', function () {
    Carbon::setTestNow('2026-10-05 09:30:00');
    [$board, $view, $group, $status] = roundTwoBoard();
    $open = $board->items()->create(['group_id' => $group->id, 'name' => 'Open', 'position' => 0]);
    $finished = $board->items()->create(['group_id' => $group->id, 'name' => 'Finished', 'position' => 1]);
    BoardItemValue::create(['item_id' => $finished->id, 'column_id' => $status->id, 'value' => 'done']);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_SCAN,
        'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00', 'timezone' => 'UTC']],
        'conditions' => [['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['done']]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
        'last_scheduled_run_at' => Carbon::parse('2026-10-04 10:00:00'),
    ]);

    expect(app(BoardAutomationService::class)->runScheduledTriggers())->toBe(1);
    expect($finished->fresh()->is_archived)->toBeTrue();
    expect($open->fresh()->is_archived)->toBeFalse();
    expect(BoardAutomationRunLog::count())->toBe(1);
});

test('an item scan needs at least one condition', function () {
    [$board, $view] = roundTwoBoard();

    $this->actingAs(User::factory()->create(), 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'item_scan',
        'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00']],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
});

test('renaming an item fires the name trigger with the old and new names', function () {
    [$board, $view, $group] = roundTwoBoard();
    $actor = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Draft', 'position' => 0]);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_NAME_CHANGED,
        'actions' => [['type' => 'post_update', 'params' => ['message' => 'Renamed from {old_value} to {new_value}']]],
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}", ['name' => 'Final'])->assertOk();

    expect($item->comments()->latest('id')->first()?->body)->toBe('Renamed from Draft to Final');
});

test('a webhook creates an item and fills its columns from the posted JSON', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $user = User::factory()->create();
    $email = roundTwoColumn($board, $view, BoardColumn::TYPE_EMAIL, 'Email');

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'webhook_received',
        'actions' => [['type' => 'create_item', 'params' => [
            'target_group_id' => $group->id,
            'item_name' => 'Lead {payload.contact.name}',
            'field_mappings' => [
                ['column_id' => $email->id, 'source' => '{payload.contact.email}'],
                ['column_id' => $status->id, 'source' => '{payload.stage}'],
            ],
        ]]],
    ])->assertCreated();

    $url = $response->json('automation.webhook_url');
    expect($url)->toContain('/api/public/automation-webhooks/');

    $this->postJson(parse_url($url, PHP_URL_PATH), ['contact' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'stage' => 'Done'])->assertStatus(202);

    $item = BoardItem::where('name', 'Lead Ada')->firstOrFail();
    expect(roundTwoValue($item, $email))->toBe('ada@example.com');
    expect(roundTwoValue($item, $status))->toBe('done');

    $this->postJson('/api/public/automation-webhooks/'.str_repeat('x', 48), [])->assertNotFound();
});

test('a webhook action refuses private addresses and signs what it sends', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'item_created',
        'actions' => [['type' => 'send_webhook', 'params' => ['url' => 'http://127.0.0.1/hook']]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.params.url']);

    // A public IP literal, so the check needs no DNS lookup.
    Http::fake(['93.184.215.14/*' => Http::response(['ok' => true])]);
    $job = new SendAutomationWebhookJob(1, 'https://93.184.215.14/in', ['event' => 'automation.run'], 'shh', null, null);
    $job->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Automation-Signature', 'sha256='.hash_hmac('sha256', '{"event":"automation.run"}', 'shh')));
});

test('copy, time tracking and connect actions write the right shapes', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $note = roundTwoColumn($board, $view, BoardColumn::TYPE_TEXT, 'Note');
    $timer = roundTwoColumn($board, $view, BoardColumn::TYPE_TIME_TRACKING, 'Timer');

    $clients = WorkspaceNavigationItem::factory()->create(['workspace_id' => $board->workspace_id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $clients_view = BoardView::factory()->create(['board_id' => $clients->id, 'is_primary' => true]);
    $clients_group = BoardGroup::factory()->create(['board_id' => $clients->id, 'board_view_id' => $clients_view->id]);
    $acme = $clients->items()->create(['group_id' => $clients_group->id, 'name' => 'Acme', 'position' => 0]);
    $client_link = roundTwoColumn($board, $view, BoardColumn::TYPE_CONNECT_BOARD, 'Client', ['linked_board_id' => $clients->id]);

    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'acme', 'position' => 0]);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'working',
        'actions' => [
            ['type' => 'copy_column_value', 'params' => ['source_column_id' => $status->id, 'target_column_id' => $note->id]],
            ['type' => 'time_tracking', 'params' => ['target_column_id' => $timer->id, 'mode' => 'start']],
            ['type' => 'connect_items', 'params' => ['target_column_id' => $client_link->id, 'match_column_id' => 'name', 'linked_match_column_id' => 'name']],
        ],
    ]);
    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'time_tracking', 'params' => ['target_column_id' => $timer->id, 'mode' => 'stop']]],
    ]);

    roundTwoSetValue($actor, $board, $item, $status, 'working');
    expect(roundTwoValue($item, $note))->toBe('Working on it');
    expect(roundTwoValue($item, $client_link))->toBe([(string) $acme->id]);
    expect(roundTwoValue($item, $timer)['running_since'])->not->toBeNull();

    Carbon::setTestNow('2026-10-01 11:30:00');
    roundTwoSetValue($actor, $board, $item, $status, 'done');
    expect(roundTwoValue($item, $timer))->toBe(['seconds' => 5400, 'running_since' => null]);
});

test('notify team reaches every active member of the team', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $team = AccountTeam::factory()->create(['name' => 'Designers']);
    $members = User::factory()->count(2)->create();
    $team->members()->attach($members->pluck('id'));
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Logo', 'position' => 0]);

    roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'notify_team', 'params' => ['team_id' => $team->id, 'message' => '{item_name} is ready']]],
    ]);

    roundTwoSetValue($actor, $board, $item, $status, 'done');

    expect(Notification::whereIn('user_id', $members->pluck('id'))->count())->toBe(2);
});

test('a test run reports what would happen and leaves nothing behind', function () {
    Queue::fake();
    [$board, $view, $group, $status] = roundTwoBoard();
    $user = User::factory()->create();
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Done']);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Invoice', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/test", [
        'view_id' => $view->id,
        'item_id' => $item->id,
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'conditions' => [['column_id' => 'name', 'condition' => 'contains', 'value' => 'invoice', 'values' => []]],
        'actions' => [
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']],
            ['type' => 'move_to_group', 'params' => ['target_group_id' => $done_group->id]],
            ['type' => 'send_email', 'params' => ['notify_user_id' => $user->id]],
        ],
    ])->assertOk();

    $response->assertJsonPath('data.passes', true)
        ->assertJsonPath('data.conditions.0.passes', true)
        ->assertJsonPath('data.actions.0.status', 'success')
        ->assertJsonPath('data.actions.1.message', 'Moved the item to "Done".')
        ->assertJsonPath('data.actions.2.message', "Would email {$user->full_name}.");

    expect($item->fresh()->group_id)->toBe($group->id);
    expect(roundTwoValue($item, $status))->toBeNull();
    expect(BoardAutomationRunLog::count())->toBe(0);
    Queue::assertNotPushed(SendEmailJob::class);
});

test('deleting a column pauses the automations that use it and blocks turning them back on', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $owner = User::factory()->create();
    $date = roundTwoColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $automation = roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'set_date', 'params' => ['target_column_id' => $date->id, 'offset_days' => 0]]],
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
    ]);

    $this->actingAs($owner, 'api')->deleteJson("/api/boards/{$board->id}/columns/{$date->id}")->assertOk();

    $automation->refresh();
    expect($automation->is_enabled)->toBeFalse();
    expect($automation->paused_reason)->toBe('A column this action uses was deleted.');
    expect(Notification::where('user_id', $owner->id)->exists())->toBeTrue();

    $this->actingAs($owner, 'api')->getJson("/api/boards/{$board->id}/automations?view_id={$view->id}")
        ->assertJsonPath('data.0.problems.0.path', 'actions.0');

    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/automations/{$automation->id}", ['is_enabled' => true])
        ->assertUnprocessable();
});

test('changes an automation makes are credited to it in the item activity', function () {
    [$board, $view, $group, $status] = roundTwoBoard();
    $actor = User::factory()->create();
    $priority = roundTwoColumn($board, $view, BoardColumn::TYPE_TEXT, 'Priority');
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Bug', 'position' => 0]);

    roundTwoAutomation($board, $view, [
        'name' => 'Escalate',
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $priority->id, 'value' => 'High']]],
    ]);

    roundTwoSetValue($actor, $board, $item, $status, 'done');

    expect(BoardItemActivity::where('column_id', $status->id)->first()->automation_name)->toBeNull();
    expect(BoardItemActivity::where('column_id', $priority->id)->first()->automation_name)->toBe('Escalate');
});

test('administrators publish board agnostic templates for every board', function () {
    $this->seed(RolePermissionSeeder::class);
    [$board, $view, $group, $status] = roundTwoBoard();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $member = User::factory()->create();
    $member->assignRole('client');
    $automation = roundTwoAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'move_to_group', 'params' => ['target_group_id' => $group->id]]],
    ]);

    $this->actingAs($member, 'api')->postJson('/api/automation-templates', ['automation_id' => $automation->id, 'name' => 'Done to group'])->assertForbidden();

    $this->actingAs($admin, 'api')->postJson('/api/automation-templates', ['automation_id' => $automation->id, 'name' => 'Done to group'])
        ->assertCreated()
        ->assertJsonPath('template.definition.trigger_column_id', null)
        ->assertJsonPath('template.definition.trigger_value', null)
        ->assertJsonPath('template.column_kinds.trigger_column_id.type', 'status');

    expect(AccountAutomationTemplate::first()->definition['actions'][0]['params'])->toBe([]);
    $this->actingAs($member, 'api')->getJson('/api/automation-templates')->assertOk()->assertJsonCount(1, 'data');
});
