<?php

use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunChange;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use App\Support\Formula\FormulaEngine;
use Illuminate\Support\Carbon;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function roundFourBoard(?Workspace $workspace = null): array
{
    $workspace ??= Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Backlog']);
    $status = roundFourColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [
        ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
        ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
    ]]);

    return [$board, $view, $group, $status];
}

function roundFourColumn(WorkspaceNavigationItem $board, BoardView $view, string $type, string $label, array $config = [], string $scope = BoardColumn::SCOPE_ITEM): BoardColumn
{
    return BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $type, 'label' => $label, 'scope' => $scope, 'config' => $config ?: null]);
}

function roundFourAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
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

function roundFourItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, ?BoardItem $parent = null): BoardItem
{
    return $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent?->id, 'name' => $name, 'position' => 0]);
}

function roundFourSet(User $actor, WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, mixed $value): void
{
    test()->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => $value],
    ])->assertOk();
}

function roundFourValue(BoardItem $item, BoardColumn $column): mixed
{
    return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
}

test('removing a person fires the person is unassigned trigger', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    [$ada, $grace] = User::factory()->count(2)->create();
    $owner = roundFourColumn($board, $view, BoardColumn::TYPE_PEOPLE, 'Owner');
    $item = roundFourItem($board, $group, 'Launch');

    $anyone = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_PERSON_UNASSIGNED,
        'trigger_column_id' => $owner->id,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']]],
    ]);
    $only_grace = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_PERSON_UNASSIGNED,
        'trigger_column_id' => $owner->id,
        'trigger_value' => $grace->id,
        'actions' => [['type' => 'post_update', 'params' => ['message' => 'Grace left']]],
    ]);

    roundFourSet($actor, $board, $item, $owner, [(string) $ada->id, (string) $grace->id]);
    expect(BoardAutomationRunLog::where('automation_id', $anyone->id)->count())->toBe(0);

    roundFourSet($actor, $board, $item, $owner, [(string) $grace->id]);
    expect(roundFourValue($item, $status))->toBe('working');
    expect(BoardAutomationRunLog::where('automation_id', $only_grace->id)->count())->toBe(0);

    roundFourSet($actor, $board, $item, $owner, []);
    expect(BoardAutomationRunLog::where('automation_id', $only_grace->id)->count())->toBe(1);
});

test('adding a file fires the file is uploaded trigger, narrowed by extension', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $files = roundFourColumn($board, $view, BoardColumn::TYPE_FILES, 'Contracts');
    $item = roundFourItem($board, $group, 'Client');

    $automation = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_FILE_UPLOADED,
        'trigger_column_id' => $files->id,
        'trigger_config' => ['extensions' => ['pdf']],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']]],
    ]);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/columns/{$files->id}/files/link", ['url' => 'https://example.com/logo.png'])->assertCreated();
    expect(BoardAutomationRunLog::where('automation_id', $automation->id)->count())->toBe(0);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/columns/{$files->id}/files/link", ['url' => 'https://example.com/contract.pdf'])->assertCreated();
    expect(roundFourValue($item, $status))->toBe('done');
});

test('an item that becomes overdue and is not done fires once per due date', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $due = roundFourColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $flag = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Reminders');
    $late = roundFourItem($board, $group, 'Late');
    $finished = roundFourItem($board, $group, 'Finished');

    $automation = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_OVERDUE,
        'trigger_column_id' => $due->id,
        'trigger_config' => ['status_column_id' => $status->id, 'done_values' => ['done'], 'time' => '08:00', 'timezone' => 'UTC'],
        'actions' => [['type' => 'adjust_number', 'params' => ['target_column_id' => $flag->id, 'amount' => 1]]],
    ]);
    $automation->forceFill(['created_at' => Carbon::parse('2026-10-01 00:00:00')])->save();

    $late->values()->create(['column_id' => $due->id, 'value' => '2026-10-05']);
    $finished->values()->create(['column_id' => $due->id, 'value' => '2026-10-05']);
    $finished->values()->create(['column_id' => $status->id, 'value' => 'done']);
    $service = app(BoardAutomationService::class);

    expect($service->runOverdueTriggers(Carbon::parse('2026-10-05 12:00:00')))->toBe(0);
    expect($service->runOverdueTriggers(Carbon::parse('2026-10-06 07:00:00')))->toBe(0);
    expect($service->runOverdueTriggers(Carbon::parse('2026-10-06 09:00:00')))->toBe(1);
    expect($service->runOverdueTriggers(Carbon::parse('2026-10-07 09:00:00')))->toBe(0);
    expect(roundFourValue($late, $flag))->toBe(1);
    expect(roundFourValue($finished, $flag))->toBeNull();
});

test('a column changes trigger matches values by column type', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $tags = roundFourColumn($board, $view, BoardColumn::TYPE_TAGS, 'Tags', ['options' => [['id' => 'urgent', 'label' => 'Urgent'], ['id' => 'later', 'label' => 'Later']]]);
    $budget = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $notes = roundFourColumn($board, $view, BoardColumn::TYPE_TEXT, 'Notes');
    $hits = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Hits');
    $item = roundFourItem($board, $group, 'Ticket');
    $bump = [['type' => 'adjust_number', 'params' => ['target_column_id' => $hits->id, 'amount' => 1]]];

    roundFourAutomation($board, $view, ['trigger_type' => 'column_changed', 'trigger_column_id' => $tags->id, 'trigger_config' => ['match' => ['operator' => 'added', 'values' => ['urgent']]], 'actions' => $bump]);
    roundFourAutomation($board, $view, ['trigger_type' => 'column_changed', 'trigger_column_id' => $budget->id, 'trigger_config' => ['match' => ['operator' => 'between', 'values' => ['10', '20']]], 'actions' => $bump]);
    roundFourAutomation($board, $view, ['trigger_type' => 'column_changed', 'trigger_column_id' => $notes->id, 'trigger_config' => ['match' => ['operator' => 'contains', 'value' => 'blocked']], 'actions' => $bump]);

    roundFourSet($actor, $board, $item, $tags, ['later']);
    roundFourSet($actor, $board, $item, $tags, ['later', 'urgent']);
    roundFourSet($actor, $board, $item, $tags, ['urgent']);
    expect(roundFourValue($item, $hits))->toBe(1);

    roundFourSet($actor, $board, $item, $budget, 25);
    roundFourSet($actor, $board, $item, $budget, 15);
    expect(roundFourValue($item, $hits))->toBe(2);

    roundFourSet($actor, $board, $item, $notes, 'All fine');
    roundFourSet($actor, $board, $item, $notes, 'We are Blocked by legal');
    expect(roundFourValue($item, $hits))->toBe(3);
});

test('rename and add or remove values keep the rest of the item', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $tags = roundFourColumn($board, $view, BoardColumn::TYPE_TAGS, 'Tags', ['options' => [['id' => 'urgent', 'label' => 'Urgent'], ['id' => 'client', 'label' => 'Client'], ['id' => 'review', 'label' => 'Review']]]);
    $watchers = roundFourColumn($board, $view, BoardColumn::TYPE_PEOPLE, 'Watchers');
    $item = roundFourItem($board, $group, 'Contract');
    $item->values()->create(['column_id' => $tags->id, 'value' => ['client', 'review']]);

    roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [
            ['type' => 'rename_item', 'params' => ['name_template' => '[Done] {item_name}']],
            ['type' => 'change_values', 'params' => ['target_column_id' => $tags->id, 'mode' => 'add', 'values' => ['urgent']]],
            ['type' => 'change_values', 'params' => ['target_column_id' => $tags->id, 'mode' => 'remove', 'values' => ['review']]],
            ['type' => 'change_values', 'params' => ['target_column_id' => $watchers->id, 'mode' => 'add', 'values' => ['__actor__']]],
        ],
    ]);

    roundFourSet($actor, $board, $item, $status, 'done');

    expect($item->fresh()->name)->toBe('[Done] Contract');
    expect(roundFourValue($item, $tags))->toBe(['client', 'urgent']);
    expect(roundFourValue($item, $watchers))->toBe([(string) $actor->id]);
});

test('connected items are updated, and an item created on another board is connected back', function () {
    $workspace = Workspace::factory()->create();
    [$board, $view, $group, $status] = roundFourBoard($workspace);
    [$other, $other_view, $other_group, $other_status] = roundFourBoard($workspace);
    $actor = User::factory()->create();
    $link = roundFourColumn($board, $view, BoardColumn::TYPE_CONNECT_BOARD, 'Tasks', ['linked_board_id' => $other->id]);
    $project = roundFourItem($board, $group, 'Project');
    $task = roundFourItem($other, $other_group, 'Task A');
    $project->values()->create(['column_id' => $link->id, 'value' => [(string) $task->id]]);

    roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'created_by_id' => $actor->id,
        'actions' => [
            ['type' => 'update_connected_items', 'params' => ['connect_column_id' => $link->id, 'linked_column_id' => $other_status->id, 'value' => 'done']],
            ['type' => 'create_item', 'params' => ['target_board_id' => $other->id, 'target_group_id' => $other_group->id, 'item_name' => 'Follow up {item_name}', 'link_column_id' => $link->id]],
        ],
    ]);

    roundFourSet($actor, $board, $project, $status, 'done');

    expect(roundFourValue($task, $other_status))->toBe('done');
    $created = BoardItem::where('board_id', $other->id)->where('name', 'Follow up Project')->first();
    expect($created)->not->toBeNull();
    expect(roundFourValue($project, $link))->toBe([(string) $task->id, (string) $created->id]);
});

test('a group wide action changes, moves or archives every item of the group', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $done = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Done']);
    $first = roundFourItem($board, $group, 'One');
    $second = roundFourItem($board, $group, 'Two');

    roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_RECURRING,
        'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00', 'timezone' => 'UTC']],
        'last_scheduled_run_at' => Carbon::parse('2026-10-01 00:00:00'),
        'actions' => [
            ['type' => 'group_items', 'params' => ['target_group_id' => $group->id, 'operation' => 'set_column_value', 'target_column_id' => $status->id, 'value' => 'done']],
            ['type' => 'group_items', 'params' => ['target_group_id' => $group->id, 'operation' => 'move_to_group', 'destination_group_id' => $done->id]],
            ['type' => 'group_items', 'params' => ['target_group_id' => $done->id, 'operation' => 'archive']],
        ],
    ]);

    app(BoardAutomationService::class)->runScheduledTriggers(Carbon::parse('2026-10-02 10:00:00'));

    expect(roundFourValue($first, $status))->toBe('done');
    expect(roundFourValue($second, $status))->toBe('done');
    expect($first->fresh()->group_id)->toBe($done->id);
    expect($second->fresh()->is_archived)->toBeTrue();
});

test('conditions read subitems, the person who made the change, updates, formulas, links, dependencies and timers', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    [$ada, $grace] = User::factory()->count(2)->create();
    $sub_status = roundFourColumn($board, $view, BoardColumn::TYPE_STATUS, 'Sub status', ['options' => [['id' => 'ok', 'label' => 'OK'], ['id' => 'stuck', 'label' => 'Stuck']]], BoardColumn::SCOPE_SUBITEM);
    $hours = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Hours');
    $double = roundFourColumn($board, $view, BoardColumn::TYPE_FORMULA, 'Double', ['expression' => '{#'.$hours->id.'} * 2']);
    $blocked_by = roundFourColumn($board, $view, BoardColumn::TYPE_DEPENDENCY, 'Blocked by');
    $timer = roundFourColumn($board, $view, BoardColumn::TYPE_TIME_TRACKING, 'Timer');
    $item = roundFourItem($board, $group, 'Parent');
    $predecessor = roundFourItem($board, $group, 'Before');
    $child = roundFourItem($board, $group, 'Child', $item);
    $child->values()->create(['column_id' => $sub_status->id, 'value' => 'ok']);
    $item->values()->create(['column_id' => $hours->id, 'value' => 6]);
    $item->values()->create(['column_id' => $blocked_by->id, 'value' => [(string) $predecessor->id]]);
    $item->values()->create(['column_id' => $timer->id, 'value' => ['seconds' => 0, 'running_since' => now()->toIso8601String()]]);
    $predecessor->values()->create(['column_id' => $status->id, 'value' => 'done']);
    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $ada->id, 'body' => 'First']);

    $automation = roundFourAutomation($board, $view, [
        'trigger_type' => 'item_created',
        'conditions' => [
            ['column_id' => '__subitems__', 'condition' => 'all_match', 'value' => '', 'values' => [], 'subitem_rule' => ['column_id' => (string) $sub_status->id, 'condition' => 'is', 'value' => '', 'values' => ['ok']]],
            ['column_id' => '__actor__', 'condition' => 'is', 'value' => '', 'values' => [(string) $ada->id]],
            ['column_id' => '__update_count__', 'condition' => 'equals', 'value' => '1', 'values' => []],
            ['column_id' => (string) $double->id, 'condition' => 'greater_than', 'value' => '10', 'values' => []],
            ['column_id' => (string) $blocked_by->id, 'condition' => 'all_done', 'value' => (string) $status->id, 'values' => ['done']],
            ['column_id' => (string) $timer->id, 'condition' => 'is_running', 'value' => '', 'values' => []],
        ],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ]);
    $service = app(BoardAutomationService::class);
    $fresh = fn () => $item->fresh(['values', 'group']);

    expect($service->conditionsMatch($automation, $fresh(), $ada))->toBeTrue();
    expect($service->conditionsMatch($automation, $fresh(), $grace))->toBeFalse();

    BoardItemValue::where('item_id', $item->id)->where('column_id', $hours->id)->update(['value' => json_encode(4)]);
    expect($service->conditionsMatch($automation, $fresh(), $ada))->toBeFalse();
    BoardItemValue::where('item_id', $item->id)->where('column_id', $hours->id)->update(['value' => json_encode(6)]);

    BoardItemValue::where('item_id', $predecessor->id)->where('column_id', $status->id)->update(['value' => json_encode('working')]);
    expect($service->conditionsMatch($automation, $fresh(), $ada))->toBeFalse();
    BoardItemValue::where('item_id', $predecessor->id)->where('column_id', $status->id)->update(['value' => json_encode('done')]);

    BoardItemValue::where('item_id', $child->id)->where('column_id', $sub_status->id)->update(['value' => json_encode('stuck')]);
    expect($service->conditionsMatch($automation, $fresh(), $ada))->toBeFalse();
});

test('copy column value reads a formula result', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $price = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Price');
    $total = roundFourColumn($board, $view, BoardColumn::TYPE_FORMULA, 'Total', ['expression' => 'ROUND({#'.$price->id.'} * 1.21, 2)']);
    $saved = roundFourColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Saved total');
    $item = roundFourItem($board, $group, 'Order');
    $item->values()->create(['column_id' => $price->id, 'value' => 100]);

    roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'copy_column_value', 'params' => ['source_column_id' => $total->id, 'target_column_id' => $saved->id]]],
    ]);

    roundFourSet($actor, $board, $item, $status, 'done');
    expect(roundFourValue($item, $saved))->toBe(121);
});

test('the formula engine matches the frontend results', function () {
    $sources = [
        '1' => ['kind' => 'number', 'title' => 'Hours', 'options' => []],
        '2' => ['kind' => 'status', 'title' => 'Status', 'options' => ['d' => 'Done']],
        '3' => ['kind' => 'date', 'title' => 'Due', 'options' => []],
    ];
    $engine = new FormulaEngine($sources, ['1' => 7.5, '2' => 'd', '3' => '2026-10-05'], 'Launch');

    expect($engine->run('{#1} * 2')['text'])->toBe('15');
    expect($engine->run('IF({#2} = "Done", "Closed", "Open")')['value'])->toBe('Closed');
    expect($engine->run('{#__name} & " " & UPPER("x")')['value'])->toBe('Launch X');
    expect($engine->run('FORMAT_DATE(ADD_DAYS({#3}, 3), "MMM D, YYYY")')['value'])->toBe('Oct 8, 2026');
    expect($engine->run('DAYS({#3}, DATE(2026, 10, 1))')['value'])->toBe(4.0);
    expect($engine->run('ROUND(2.345, 2)')['value'])->toBe(2.35);
    expect($engine->run('1 / 0'))->toMatchArray(['ok' => false, 'code' => '#DIV/0!']);
    expect($engine->run('IFERROR(1 / 0, "n/a")')['value'])->toBe('n/a');
    expect($engine->run('SUM(1, 2'))->toMatchArray(['ok' => false, 'code' => '#ERROR!']);
});

test('a run can be undone, and changes made again since are left alone', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Done']);
    $note = roundFourColumn($board, $view, BoardColumn::TYPE_TEXT, 'Note');
    $priority = roundFourColumn($board, $view, BoardColumn::TYPE_TEXT, 'Priority');
    $item = roundFourItem($board, $group, 'Order');

    $automation = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $note->id, 'value' => 'Closed']],
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $priority->id, 'value' => 'Low']],
            ['type' => 'rename_item', 'params' => ['name_template' => '{item_name} (closed)']],
            ['type' => 'move_to_group', 'params' => ['target_group_id' => $done_group->id]],
            ['type' => 'create_subitem', 'params' => ['subitem_names' => ['Invoice']]],
        ],
    ]);

    roundFourSet($actor, $board, $item, $status, 'done');
    expect($item->fresh()->group_id)->toBe($done_group->id);
    roundFourSet($actor, $board, $item, $priority, 'High');

    $log = BoardAutomationRunLog::where('automation_id', $automation->id)->orderBy('id')->first();
    $this->actingAs($actor, 'api')->getJson("/api/boards/{$board->id}/automations/runs?view_id={$view->id}")
        ->assertOk()->assertJsonPath('data.0.can_undo', true);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/automations/runs/{$log->id}/undo")
        ->assertOk()->assertJsonPath('data.reverted', 4)->assertJsonCount(1, 'data.skipped');

    $item->refresh();
    expect($item->name)->toBe('Order');
    expect($item->group_id)->toBe($group->id);
    expect(roundFourValue($item, $note))->toBeNull();
    expect(roundFourValue($item, $priority))->toBe('High');
    expect(BoardItem::where('parent_id', $item->id)->count())->toBe(0);
    expect(BoardAutomationRunChange::where('run_uuid', $log->run_uuid)->whereNull('undone_at')->count())->toBe(0);
    expect($log->fresh()->undone_at)->not->toBeNull();

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/automations/runs/{$log->id}/undo")->assertStatus(422);
});

test('an automation that fails too many runs in a row pauses itself', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $actor = User::factory()->create();
    $other = roundFourBoard();
    $item = roundFourItem($board, $group, 'Order');
    BoardAutomationSetting::forBoard($board->id)->fill(['auto_pause_after_failures' => 2])->save();

    $automation = roundFourAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'failure_alert' => 'none',
        // Another workspace's board, so the owner check fails every time.
        'actions' => [['type' => 'create_item', 'params' => ['target_board_id' => $other[0]->id, 'target_group_id' => $other[2]->id, 'item_name' => 'Copy']]],
    ]);

    roundFourSet($actor, $board, $item, $status, 'working');
    expect($automation->fresh()->consecutive_failures)->toBe(1);
    expect($automation->fresh()->is_enabled)->toBeTrue();

    roundFourSet($actor, $board, $item, $status, 'done');
    $automation->refresh();
    expect($automation->consecutive_failures)->toBe(2);
    expect($automation->is_enabled)->toBeFalse();
    expect($automation->paused_reason)->toContain('failed 2 runs in a row');

    $this->actingAs($actor, 'api')->putJson("/api/boards/{$board->id}/automations/settings", ['auto_pause_after_failures' => 0])
        ->assertOk()->assertJsonPath('data.auto_pause_after_failures', 0);
});

test('automations are exported to a file and imported into another board by name', function () {
    $workspace = Workspace::factory()->create();
    [$board, $view, $group, $status] = roundFourBoard($workspace);
    [$target, $target_view, $target_group, $target_status] = roundFourBoard($workspace);
    $user = User::factory()->create();
    $done = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Shipped']);
    BoardGroup::factory()->create(['board_id' => $target->id, 'board_view_id' => $target_view->id, 'name' => 'Shipped']);

    roundFourAutomation($board, $view, [
        'name' => 'Ship it',
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'move_to_group', 'params' => ['target_group_id' => $done->id]]],
    ]);

    $file = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/export", ['view_id' => $view->id])
        ->assertOk()->assertJsonPath('data.format', 'workspace97.automations')->json('data');

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$target->id}/automations/import", ['view_id' => $target_view->id, 'file' => $file])
        ->assertCreated()->assertJsonPath('data.0.unmapped', []);

    $imported = BoardAutomation::find($response->json('data.0.automation_id'));
    expect($imported->board_view_id)->toBe($target_view->id);
    expect($imported->is_enabled)->toBeFalse();
    expect($imported->trigger_column_id)->toBe($target_status->id);
    expect($imported->resolvedActions()[0]['params']['target_group_id'])->toBe(BoardGroup::where('board_view_id', $target_view->id)->where('name', 'Shipped')->value('id'));

    $this->actingAs($user, 'api')->postJson("/api/boards/{$target->id}/automations/import", ['file' => ['format' => 'other', 'version' => 1, 'automations' => []]])
        ->assertStatus(422);
});

test('the impact preview lists the items an automation would act on', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $user = User::factory()->create();
    $due = roundFourColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $open = roundFourItem($board, $group, 'Open');
    $closed = roundFourItem($board, $group, 'Closed');
    $closed->values()->create(['column_id' => $status->id, 'value' => 'done']);
    $open->values()->create(['column_id' => $due->id, 'value' => now()->addDays(3)->toDateString()]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/preview", [
        'view_id' => $view->id,
        'trigger_type' => 'item_created',
        'conditions' => [['column_id' => (string) $status->id, 'condition' => 'is_not', 'value' => '', 'values' => ['done']]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertOk()
        ->assertJsonPath('data.mode', 'conditions')
        ->assertJsonPath('data.total_items', 2)
        ->assertJsonPath('data.matching_count', 1)
        ->assertJsonPath('data.items.0.name', 'Open')
        ->assertJsonPath('data.sample.actions.0.status', 'success');

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/preview", [
        'view_id' => $view->id,
        'trigger_type' => 'date_arrived',
        'trigger_column_id' => $due->id,
        'trigger_config' => ['offset_days' => -1, 'timezone' => 'UTC'],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertOk()
        ->assertJsonPath('data.mode', 'upcoming')
        ->assertJsonPath('data.items.0.fires_on', now()->addDays(2)->toDateString());

    expect($open->fresh()->is_archived)->toBeFalse();
});

test('the builder validates the new triggers, actions and conditions', function () {
    [$board, $view, $group, $status] = roundFourBoard();
    $user = User::factory()->create();
    $due = roundFourColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $tags = roundFourColumn($board, $view, BoardColumn::TYPE_TAGS, 'Tags', ['options' => [['id' => 'a', 'label' => 'A']]]);
    $base = ['view_id' => $view->id, 'trigger_type' => 'status_changed', 'trigger_column_id' => $status->id];

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id, 'trigger_type' => 'item_overdue', 'trigger_column_id' => $due->id,
        'trigger_config' => ['status_column_id' => $status->id, 'done_values' => ['nope']],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertStatus(422)->assertJsonValidationErrors(['trigger_config.done_values']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'actions' => [['type' => 'change_values', 'params' => ['target_column_id' => $status->id, 'mode' => 'add', 'values' => ['done']]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['actions.0.params.target_column_id']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'conditions' => [['column_id' => '__subitems__', 'condition' => 'all_match', 'value' => '', 'values' => [], 'subitem_rule' => ['column_id' => (string) $tags->id, 'condition' => 'is', 'values' => ['a']]]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertStatus(422)->assertJsonValidationErrors(['conditions.0.subitem_rule.column_id']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'conditions' => [['column_id' => '__actor__', 'condition' => 'is', 'value' => '', 'values' => [(string) $user->id]]],
        'actions' => [
            ['type' => 'rename_item', 'params' => ['name_template' => '{item_name} done']],
            ['type' => 'group_items', 'params' => ['from_item_group' => true, 'operation' => 'archive']],
        ],
    ])->assertCreated()->assertJsonPath('automation.actions.1.type', 'group_items');
});
