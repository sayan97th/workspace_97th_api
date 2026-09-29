<?php

use App\Models\AccountSetting;
use App\Models\AutomationUsageMonth;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationActionRunner;
use App\Services\Board\BoardAutomationRunUndoer;
use App\Services\Board\BoardAutomationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

/**
 * Round six of the Automations center: conditions shaped by column type (timeline, checklist,
 * vote, rating, email, phone, link), the "status is stuck" and "item is not updated" triggers,
 * the position and sort actions, and the monthly action quota.
 *
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function roundSixBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Pipeline']);
    $status = roundSixColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [
        ['id' => 'new', 'label' => 'New', 'color' => '#579bfc'],
        ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c'],
        ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
    ]]);

    return [$board, $view, $group, $status];
}

function roundSixColumn(WorkspaceNavigationItem $board, BoardView $view, string $type, string $label, array $config = []): BoardColumn
{
    return BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $type, 'label' => $label, 'scope' => BoardColumn::SCOPE_ITEM, 'config' => $config ?: null]);
}

function roundSixAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
{
    $actions = $attributes['actions'] ?? [['type' => 'archive_item', 'params' => []]];

    return BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'action_type' => $actions[0]['type'],
        'action_params' => $actions[0]['params'],
        ...$attributes,
        'actions' => $actions,
    ]);
}

function roundSixItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, int $position = 0): BoardItem
{
    return $board->items()->create(['group_id' => $group->id, 'name' => $name, 'position' => $position]);
}

function roundSixSet(BoardItem $item, BoardColumn $column, mixed $value): void
{
    BoardItemValue::updateOrCreate(['item_id' => $item->id, 'column_id' => $column->id], ['value' => $value]);
}

/**
 * Whether one rule on `$column` passes for `$item`.
 */
function roundSixPasses(WorkspaceNavigationItem $board, BoardView $view, BoardItem $item, BoardColumn $column, string $operator, string $value = ''): bool
{
    $automation = roundSixAutomation($board, $view, ['conditions' => [['column_id' => (string) $column->id, 'condition' => $operator, 'value' => $value, 'values' => []]]]);

    return app(BoardAutomationService::class)->conditionsMatch($automation, $item->fresh('values'));
}

function roundSixOrder(BoardGroup $group): array
{
    return BoardItem::where('group_id', $group->id)->whereNull('parent_id')->orderBy('position')->orderBy('id')->pluck('name')->all();
}

// ── Conditions by column type ─────────────────────────────────────────────────

test('timeline conditions read its start, end, length and whether it includes today', function () {
    Carbon::setTestNow('2026-10-07 09:00:00');
    [$board, $view, $group] = roundSixBoard();
    $timeline = roundSixColumn($board, $view, BoardColumn::TYPE_TIMELINE, 'Timeline');
    $item = roundSixItem($board, $group, 'Launch');
    roundSixSet($item, $timeline, ['start' => '2026-10-05', 'end' => '2026-10-09']);
    $empty = roundSixItem($board, $group, 'No dates');

    expect(roundSixPasses($board, $view, $item, $timeline, 'start_before', 'today'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'start_is', 'this_week'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'end_after', '2026-10-09'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'end_is', '2026-10-09'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'includes_today'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'duration_equals', '5'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $timeline, 'duration_greater_than', '5'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $empty, $timeline, 'includes_today'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $empty, $timeline, 'not_includes_today'))->toBeTrue()
        // Rules saved before this round keep reading the timeline as a range.
        ->and(roundSixPasses($board, $view, $item, $timeline, 'is', 'today'))->toBeTrue();
    Carbon::setTestNow();
});

test('checklist, vote and rating conditions count tasks, votes and stars', function () {
    [$board, $view, $group] = roundSixBoard();
    $checklist = roundSixColumn($board, $view, BoardColumn::TYPE_CHECKLIST, 'Tasks');
    $vote = roundSixColumn($board, $view, BoardColumn::TYPE_VOTE, 'Votes');
    $rating = roundSixColumn($board, $view, BoardColumn::TYPE_RATING, 'Rating');
    $item = roundSixItem($board, $group, 'Feature');
    roundSixSet($item, $checklist, [
        ['id' => 'a', 'text' => 'Design', 'is_done' => true],
        ['id' => 'b', 'text' => 'Build', 'is_done' => true],
        ['id' => 'c', 'text' => 'Ship', 'is_done' => false],
    ]);
    roundSixSet($item, $vote, [1, 2, 3]);
    roundSixSet($item, $rating, 4);
    $done = roundSixItem($board, $group, 'Done feature');
    roundSixSet($done, $checklist, [['id' => 'a', 'text' => 'Design', 'is_done' => true]]);

    expect(roundSixPasses($board, $view, $item, $checklist, 'is_complete'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $done, $checklist, 'is_complete'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $checklist, 'is_not_complete'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $checklist, 'progress_at_least', '60'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $checklist, 'progress_below', '60'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $item, $checklist, 'open_tasks_greater_than', '0'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $checklist, 'contains', 'ship'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $vote, 'votes_at_least', '3'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $vote, 'votes_less_than', '3'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $item, $rating, 'greater_or_equal', '4'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $rating, 'less_than', '4'))->toBeFalse();
});

test('email, phone and link conditions read domains, country codes and link parts', function () {
    [$board, $view, $group] = roundSixBoard();
    $email = roundSixColumn($board, $view, BoardColumn::TYPE_EMAIL, 'Email');
    $phone = roundSixColumn($board, $view, BoardColumn::TYPE_PHONE, 'Phone');
    $link = roundSixColumn($board, $view, BoardColumn::TYPE_LINK, 'Website');
    $item = roundSixItem($board, $group, 'Lead');
    roundSixSet($item, $email, 'ana@mail.acme.com');
    roundSixSet($item, $phone, '+52 55 1234 5678');
    roundSixSet($item, $link, ['url' => 'https://www.docs.example.org/guide', 'text' => 'Onboarding guide']);
    $broken = roundSixItem($board, $group, 'Broken lead');
    roundSixSet($broken, $email, 'not an email');
    roundSixSet($broken, $phone, '0052 55 1234');

    expect(roundSixPasses($board, $view, $item, $email, 'domain_is', 'gmail.com, acme.com'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $email, 'domain_is_not', 'acme.com'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $item, $email, 'is_valid'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $broken, $email, 'is_not_valid'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $phone, 'country_code_is', '+52'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $broken, $phone, 'country_code_is', '52'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $phone, 'country_code_is_not', '1'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $link, 'domain_is', 'example.org'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $link, 'url_contains', '/guide'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $link, 'label_contains', 'guide'))->toBeTrue()
        ->and(roundSixPasses($board, $view, $item, $link, 'label_contains', 'docs'))->toBeFalse()
        ->and(roundSixPasses($board, $view, $item, $link, 'is_valid'))->toBeTrue();
});

test('the new operators save through the API and reject dynamic values they cannot use', function () {
    [$board, $view, , $status] = roundSixBoard();
    $user = User::factory()->create();
    $checklist = roundSixColumn($board, $view, BoardColumn::TYPE_CHECKLIST, 'Tasks');
    $timeline = roundSixColumn($board, $view, BoardColumn::TYPE_TIMELINE, 'Timeline');
    $payload = fn (array $conditions) => [
        'view_id' => $view->id,
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'conditions' => $conditions,
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ];

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload([
        ['column_id' => (string) $checklist->id, 'condition' => 'is_complete', 'value' => '', 'values' => []],
        ['column_id' => (string) $timeline->id, 'condition' => 'end_before', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'today', 'offset_days' => 7]],
    ]))->assertCreated()->assertJsonPath('automation.conditions.0.condition', 'is_complete');

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", $payload([
        ['column_id' => (string) $timeline->id, 'condition' => 'duration_greater_than', 'value' => '', 'values' => [], 'dynamic' => ['source' => 'today']],
    ]))->assertUnprocessable()->assertJsonValidationErrors('conditions.0.dynamic');
});

test('a timeline date trigger can watch only its end', function () {
    [$board, $view, $group] = roundSixBoard();
    $user = User::factory()->create();
    $timeline = roundSixColumn($board, $view, BoardColumn::TYPE_TIMELINE, 'Timeline');
    $flag = roundSixColumn($board, $view, BoardColumn::TYPE_TEXT, 'Flag');
    roundSixAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_DATE_CHANGED,
        'trigger_column_id' => $timeline->id,
        'trigger_config' => ['timeline_part' => 'end'],
        'actions' => [['type' => 'adjust_number', 'params' => ['target_column_id' => $flag->id, 'amount' => 1]]],
    ]);
    $item = roundSixItem($board, $group, 'Launch');
    $set = fn (array $value) => $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $timeline->id => $value]])->assertOk();
    $runs = fn () => BoardAutomationRunLog::where('board_item_id', $item->id)->count();

    $set(['start' => '2026-10-01', 'end' => '2026-10-05']);
    expect($runs())->toBe(1);
    $set(['start' => '2026-10-02', 'end' => '2026-10-05']);
    expect($runs())->toBe(1);
    $set(['start' => '2026-10-02', 'end' => '2026-10-08']);
    expect($runs())->toBe(2);
});

// ── Stuck and not updated ─────────────────────────────────────────────────────

test('a stuck status fires once per stretch and again after it changes and gets stuck again', function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
    [$board, $view, $group, $status] = roundSixBoard();
    $automation = roundSixAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_STUCK,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'stuck',
        'trigger_config' => ['amount' => 2, 'unit' => 'days'],
        'owner_id' => User::factory()->create()->id,
        'actions' => [['type' => 'post_update', 'params' => ['message' => 'Still stuck']]],
    ]);
    $stuck = roundSixItem($board, $group, 'Blocked deal');
    $moving = roundSixItem($board, $group, 'Moving deal');
    roundSixSet($stuck, $status, 'stuck');
    roundSixSet($moving, $status, 'new');
    $service = app(BoardAutomationService::class);
    $posted = fn (BoardItem $item) => BoardItemComment::where('item_id', $item->id)->count();

    Carbon::setTestNow('2026-10-02 09:00:00');
    expect($service->runQuietTriggers())->toBe(0);

    Carbon::setTestNow('2026-10-03 10:00:00');
    expect($service->runQuietTriggers())->toBe(1)
        ->and($service->runQuietTriggers())->toBe(0)
        ->and($posted($stuck))->toBe(1)
        ->and($posted($moving))->toBe(0);

    roundSixSet($stuck, $status, 'new');
    roundSixSet($stuck, $status, 'stuck');
    Carbon::setTestNow('2026-10-05 11:00:00');
    expect($service->runQuietTriggers())->toBe(1)
        ->and($posted($stuck))->toBe(2)
        ->and($automation->fresh()->quietPeriodMinutes())->toBe(2880);
    Carbon::setTestNow();
});

test('an item with no change or update for a while fires once, only in the chosen group', function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
    [$board, $view, $group, $status] = roundSixBoard();
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Archive later']);
    $flag = roundSixColumn($board, $view, BoardColumn::TYPE_CHECKBOX, 'Needs attention');
    roundSixAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_STALE,
        'trigger_config' => ['amount' => 12, 'unit' => 'hours', 'group_id' => $group->id],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $flag->id, 'value' => true]]],
    ]);
    $quiet = roundSixItem($board, $group, 'Quiet');
    $talked = roundSixItem($board, $group, 'Talked about');
    $elsewhere = roundSixItem($board, $other_group, 'Elsewhere');
    $service = app(BoardAutomationService::class);

    Carbon::setTestNow('2026-10-01 20:00:00');
    BoardItemComment::create(['item_id' => $talked->id, 'user_id' => User::factory()->create()->id, 'body' => 'Any news?']);

    Carbon::setTestNow('2026-10-01 22:00:00');
    expect($service->runQuietTriggers())->toBe(1)
        ->and(BoardItemValue::where('item_id', $quiet->id)->where('column_id', $flag->id)->value('value'))->toBeTrue()
        ->and(BoardItemValue::where('item_id', $talked->id)->where('column_id', $flag->id)->exists())->toBeFalse()
        ->and(BoardItemValue::where('item_id', $elsewhere->id)->where('column_id', $flag->id)->exists())->toBeFalse();

    Carbon::setTestNow('2026-10-02 09:00:00');
    expect($service->runQuietTriggers())->toBe(1);
    Carbon::setTestNow();
});

test('stuck and not updated triggers validate how long they wait', function () {
    [$board, $view, , $status] = roundSixBoard();
    $user = User::factory()->create();
    $archive = [['type' => 'archive_item', 'params' => []]];
    $post = fn (array $payload) => $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", ['view_id' => $view->id, 'actions' => $archive, ...$payload]);

    $post(['trigger_type' => 'item_stale', 'trigger_config' => []])->assertUnprocessable()->assertJsonValidationErrors('trigger_config.amount');
    $post(['trigger_type' => 'item_stale', 'trigger_config' => ['amount' => 400, 'unit' => 'days']])->assertUnprocessable()->assertJsonValidationErrors('trigger_config.amount');
    $post(['trigger_type' => 'status_stuck', 'trigger_column_id' => $status->id, 'trigger_value' => 'missing', 'trigger_config' => ['amount' => 3, 'unit' => 'days']])
        ->assertUnprocessable()->assertJsonValidationErrors('trigger_value');
    $post(['trigger_type' => 'status_stuck', 'trigger_column_id' => $status->id, 'trigger_value' => 'stuck', 'trigger_config' => ['amount' => 3, 'unit' => 'days']])
        ->assertCreated()->assertJsonPath('automation.trigger_config.amount', 3);
});

// ── Position and sort ─────────────────────────────────────────────────────────

test('move to top or bottom reorders the group and can be undone', function () {
    [$board, $view, $group, $status] = roundSixBoard();
    $user = User::factory()->create();
    roundSixAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'stuck',
        'actions' => [['type' => 'move_item_position', 'params' => ['position' => 'top']]],
    ]);
    roundSixItem($board, $group, 'First', 0);
    roundSixItem($board, $group, 'Second', 1);
    $third = roundSixItem($board, $group, 'Third', 2);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$third->id}/values", ['values' => [(string) $status->id => 'stuck']])->assertOk();
    expect(roundSixOrder($group))->toBe(['Third', 'First', 'Second']);

    $run_uuid = BoardAutomationRunLog::where('action_type', 'move_item_position')->value('run_uuid');
    $result = app(BoardAutomationRunUndoer::class)->undo($run_uuid, $user);
    expect($result['reverted'])->toBeGreaterThanOrEqual(1)
        ->and(roundSixOrder($group))->toBe(['First', 'Second', 'Third']);
});

test('sort group orders by label order or number, empty values last', function () {
    [$board, $view, $group, $status] = roundSixBoard();
    $budget = roundSixColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $items = [];
    foreach ([['Alpha', 'done', 10], ['Beta', null, 30], ['Gamma', 'new', null], ['Delta', 'stuck', 20]] as $position => [$name, $label, $amount]) {
        $items[$name] = roundSixItem($board, $group, $name, $position);
        if ($label) {
            roundSixSet($items[$name], $status, $label);
        }
        if ($amount !== null) {
            roundSixSet($items[$name], $budget, $amount);
        }
    }
    $run = function (array $params) use ($board, $view, $group) {
        $action = ['type' => 'sort_group', 'params' => ['target_group_id' => $group->id, ...$params]];
        app(BoardAutomationActionRunner::class)->run(roundSixAutomation($board, $view, ['actions' => [$action]]), $action, null, null);
    };

    $run(['sort_by' => 'column', 'sort_column_id' => $status->id, 'direction' => 'asc']);
    expect(roundSixOrder($group))->toBe(['Gamma', 'Delta', 'Alpha', 'Beta']);

    $run(['sort_by' => 'column', 'sort_column_id' => $budget->id, 'direction' => 'desc']);
    expect(roundSixOrder($group))->toBe(['Beta', 'Delta', 'Alpha', 'Gamma']);

    $run(['sort_by' => 'name', 'direction' => 'asc']);
    expect(roundSixOrder($group))->toBe(['Alpha', 'Beta', 'Delta', 'Gamma']);
});

test('sort group needs a column when it sorts by one, and works without an item', function () {
    [$board, $view, $group] = roundSixBoard();
    $user = User::factory()->create();
    $post = fn (array $params) => $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'recurring',
        'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00']],
        'actions' => [['type' => 'sort_group', 'params' => ['target_group_id' => $group->id, 'direction' => 'asc', ...$params]]],
    ]);

    $post(['sort_by' => 'column'])->assertUnprocessable()->assertJsonValidationErrors('actions.0.params.sort_column_id');
    $post(['sort_by' => 'created_at'])->assertCreated();
});

// ── Monthly quota ─────────────────────────────────────────────────────────────

test('actions stop at the monthly limit and admins are warned at 80 and 100 percent', function () {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-10-10 09:00:00');
    [$board, $view, $group, $status] = roundSixBoard();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    AccountSetting::current()->update(['automation_monthly_action_limit' => 5]);
    $counter = roundSixColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Counter');
    roundSixAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'adjust_number', 'params' => ['target_column_id' => $counter->id, 'amount' => 1]]],
    ]);
    $item = roundSixItem($board, $group, 'Busy item');
    $warnings = fn () => Notification::where('user_id', $admin->id)->where('type', Notification::TYPE_AUTOMATION)->pluck('action_label')->all();

    foreach (['new', 'stuck', 'done', 'new'] as $label) {
        $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => $label]])->assertOk();
    }
    expect(AutomationUsageMonth::where('month', '2026-10')->value('action_count'))->toBe(4)
        ->and($warnings())->toBe(["Automations used 80% of this month's actions"]);

    foreach (['stuck', 'done'] as $label) {
        $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => $label]])->assertOk();
    }
    expect(BoardItemValue::where('item_id', $item->id)->where('column_id', $counter->id)->value('value'))->toBe(5)
        ->and(BoardAutomationRunLog::where('status', BoardAutomationRunLog::STATUS_SKIPPED)->latest('id')->value('message'))->toContain('used all 5 automation actions')
        ->and($warnings())->toHaveCount(2);

    // A new month starts the count again.
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'new']])->assertOk();
    expect(BoardItemValue::where('item_id', $item->id)->where('column_id', $counter->id)->value('value'))->toBe(6);
    Carbon::setTestNow();
});

test('everyone reads the usage, only admins change the limit', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $member = User::factory()->create();
    AutomationUsageMonth::create(['month' => now()->format('Y-m'), 'action_count' => 30]);

    $this->actingAs($member, 'api')->getJson('/api/automations/usage')
        ->assertOk()->assertJsonPath('data.used', 30)->assertJsonPath('data.limit', null)->assertJsonPath('data.can_manage', false);
    $this->actingAs($member, 'api')->putJson('/api/automations/usage', ['monthly_action_limit' => 100])->assertForbidden();

    $this->actingAs($admin, 'api')->putJson('/api/automations/usage', ['monthly_action_limit' => 120])
        ->assertOk()->assertJsonPath('data.limit', 120)->assertJsonPath('data.remaining', 90)->assertJsonPath('data.percent', 25);
    $this->actingAs($admin, 'api')->putJson('/api/automations/usage', ['monthly_action_limit' => 0])->assertUnprocessable();
    $this->actingAs($admin, 'api')->putJson('/api/automations/usage', ['monthly_action_limit' => null])->assertOk()->assertJsonPath('data.limit', null);
});
