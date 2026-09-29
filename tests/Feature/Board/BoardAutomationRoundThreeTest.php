<?php

use App\Jobs\SendEmailJob;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationDelayedRun;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationSetting;
use App\Models\BoardAutomationVersion;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationMessageRenderer;
use App\Services\Board\BoardAutomationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function roundThreeBoard(?Workspace $workspace = null): array
{
    $workspace ??= Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Sprint 1']);
    $status = roundThreeColumn($board, $view, BoardColumn::TYPE_STATUS, 'Status', ['options' => [
        ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
        ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
    ]]);

    return [$board, $view, $group, $status];
}

function roundThreeColumn(WorkspaceNavigationItem $board, BoardView $view, string $type, string $label, array $config = [], string $scope = BoardColumn::SCOPE_ITEM): BoardColumn
{
    return BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => $type, 'label' => $label, 'scope' => $scope, 'config' => $config ?: null]);
}

function roundThreeAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
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

function roundThreeItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, ?BoardItem $parent = null): BoardItem
{
    return $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent?->id, 'name' => $name, 'position' => 0]);
}

function roundThreeSet(User $actor, WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, mixed $value): void
{
    test()->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => $value],
    ])->assertOk();
}

function roundThreeValue(BoardItem $item, BoardColumn $column): mixed
{
    return BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
}

test('pressing a button column runs the automations watching it', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $button = roundThreeColumn($board, $view, BoardColumn::TYPE_BUTTON, 'Approve', ['button_label' => 'Approve', 'button_color' => '#00c875']);
    $item = roundThreeItem($board, $group, 'Invoice');

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_BUTTON_CLICKED,
        'trigger_column_id' => $button->id,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']]],
    ]);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/buttons/{$button->id}")
        ->assertOk()->assertJsonPath('automations_run', 1);
    expect(roundThreeValue($item, $status))->toBe('done');

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items/{$item->id}/buttons/{$status->id}")->assertStatus(422);
});

test('a number threshold fires once when the number crosses it', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $budget = roundThreeColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Budget');
    $item = roundThreeItem($board, $group, 'Campaign');

    $automation = roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_NUMBER_THRESHOLD,
        'trigger_column_id' => $budget->id,
        'trigger_config' => ['operator' => 'above', 'threshold' => 100],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']]],
    ]);

    roundThreeSet($actor, $board, $item, $budget, 90);
    expect(BoardAutomationRunLog::where('automation_id', $automation->id)->count())->toBe(0);

    roundThreeSet($actor, $board, $item, $budget, 150);
    roundThreeSet($actor, $board, $item, $budget, 180);
    expect(BoardAutomationRunLog::where('automation_id', $automation->id)->where('status', 'success')->count())->toBe(1);
    expect(roundThreeValue($item, $status))->toBe('working');
});

test('checklist triggers fire when a task is checked and when every task is done', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $checklist = roundThreeColumn($board, $view, BoardColumn::TYPE_CHECKLIST, 'Launch list');
    $counter = roundThreeColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Checked');
    $item = roundThreeItem($board, $group, 'Release');

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_CHECKLIST_ITEM_CHECKED,
        'trigger_column_id' => $checklist->id,
        'trigger_value' => 'qa',
        'actions' => [['type' => 'adjust_number', 'params' => ['target_column_id' => $counter->id, 'amount' => 1]]],
    ]);
    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_CHECKLIST_COMPLETED,
        'trigger_column_id' => $checklist->id,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']]],
    ]);

    $tasks = [['id' => 'a', 'text' => 'QA', 'is_done' => false], ['id' => 'b', 'text' => 'Docs', 'is_done' => false]];
    roundThreeSet($actor, $board, $item, $checklist, $tasks);
    roundThreeSet($actor, $board, $item, $checklist, [['id' => 'a', 'text' => 'QA', 'is_done' => true], $tasks[1]]);
    expect(roundThreeValue($item, $counter))->toBe(1);
    expect(roundThreeValue($item, $status))->toBeNull();

    roundThreeSet($actor, $board, $item, $checklist, [['id' => 'a', 'text' => 'QA', 'is_done' => true], ['id' => 'b', 'text' => 'Docs', 'is_done' => true]]);
    expect(roundThreeValue($item, $counter))->toBe(1);
    expect(roundThreeValue($item, $status))->toBe('done');
});

test('moving an item onto a board and restoring an item fire their triggers', function () {
    $workspace = Workspace::factory()->create();
    [$source, $source_view, $source_group] = roundThreeBoard($workspace);
    [$target, $target_view, $target_group, $target_status] = roundThreeBoard($workspace);
    $actor = User::factory()->create();

    roundThreeAutomation($target, $target_view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_MOVED_TO_BOARD,
        'trigger_config' => ['from_board_id' => $source->id],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $target_status->id, 'value' => 'working']]],
    ]);
    $restored = roundThreeAutomation($source, $source_view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_RESTORED,
        'actions' => [['type' => 'post_update', 'params' => ['message' => 'Back again']]],
        'owner_id' => $actor->id,
    ]);

    $item = roundThreeItem($source, $source_group, 'Lead');
    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$source->id}/items/{$item->id}/board", [
        'target_board_id' => $target->id,
        'target_group_id' => $target_group->id,
    ])->assertOk();
    expect(roundThreeValue($item->fresh(), $target_status))->toBe('working');

    $archived = roundThreeItem($source, $source_group, 'Old lead');
    $archived->update(['is_archived' => true]);
    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$source->id}/trash/{$archived->id}/restore")->assertOk();
    expect(BoardAutomationRunLog::where('automation_id', $restored->id)->where('status', 'success')->exists())->toBeTrue();
});

test('a wait step stores the rest of the run and continues it later, or cancels when the conditions fail', function () {
    Carbon::setTestNow('2026-10-05 09:00:00');
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $priority = roundThreeColumn($board, $view, BoardColumn::TYPE_TEXT, 'Note');
    $first = roundThreeItem($board, $group, 'First');
    $second = roundThreeItem($board, $group, 'Second');

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'working',
        'conditions' => [['column_id' => (string) $status->id, 'condition' => 'is', 'value' => '', 'values' => ['working']]],
        'actions' => [
            ['type' => 'wait', 'params' => ['amount' => 2, 'unit' => 'hours', 'recheck_conditions' => true]],
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $priority->id, 'value' => 'Still working']],
        ],
    ]);

    roundThreeSet($actor, $board, $first, $status, 'working');
    roundThreeSet($actor, $board, $second, $status, 'working');
    expect(BoardAutomationDelayedRun::where('status', 'pending')->count())->toBe(2);
    expect(roundThreeValue($first, $priority))->toBeNull();

    roundThreeSet($actor, $board, $second, $status, 'done');
    Carbon::setTestNow('2026-10-05 11:01:00');
    expect(app(BoardAutomationService::class)->runDelayedRuns())->toBe(2);

    expect(roundThreeValue($first, $priority))->toBe('Still working');
    expect(roundThreeValue($second, $priority))->toBeNull();
    expect(BoardAutomationDelayedRun::where('board_item_id', $second->id)->value('status'))->toBe('cancelled');
});

test('the otherwise branch runs when the item fails the conditions, and or groups combine rules', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $amount = roundThreeColumn($board, $view, BoardColumn::TYPE_NUMBER, 'Amount');
    $result = roundThreeColumn($board, $view, BoardColumn::TYPE_TEXT, 'Result');
    $big = roundThreeItem($board, $group, 'Big deal');
    $small = roundThreeItem($board, $group, 'Small deal');
    $vip = roundThreeItem($board, $group, 'VIP deal');
    BoardItemValue::create(['item_id' => $big->id, 'column_id' => $amount->id, 'value' => 5000]);
    BoardItemValue::create(['item_id' => $small->id, 'column_id' => $amount->id, 'value' => 10]);
    BoardItemValue::create(['item_id' => $vip->id, 'column_id' => $amount->id, 'value' => 10]);

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'conditions' => [['column_id' => (string) $amount->id, 'condition' => 'greater_than', 'value' => '1000', 'values' => []]],
        'condition_operator' => 'or',
        'condition_groups' => [['join_operator' => 'and', 'rules' => [['column_id' => 'name', 'condition' => 'contains', 'value' => 'VIP', 'values' => []]]]],
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $result->id, 'value' => 'Approved']]],
        'else_actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $result->id, 'value' => 'Review']]],
    ]);

    foreach ([$big, $small, $vip] as $item) {
        roundThreeSet($actor, $board, $item, $status, 'done');
    }

    expect(roundThreeValue($big, $result))->toBe('Approved');
    expect(roundThreeValue($vip, $result))->toBe('Approved');
    expect(roundThreeValue($small, $result))->toBe('Review');
    expect(BoardAutomationRunLog::where('board_item_id', $small->id)->value('branch'))->toBe('else');
});

test('round robin assigns in turn and cascades reach subitems, the parent and checklists', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    [$ada, $grace] = User::factory()->count(2)->create()->all();
    $owner = roundThreeColumn($board, $view, BoardColumn::TYPE_PEOPLE, 'Owner');
    $sub_status = roundThreeColumn($board, $view, BoardColumn::TYPE_STATUS, 'Sub status', ['options' => [['id' => 'sub_done', 'label' => 'Done', 'color' => '#00c875']]], BoardColumn::SCOPE_SUBITEM);
    $list = roundThreeColumn($board, $view, BoardColumn::TYPE_CHECKLIST, 'Steps');

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'actions' => [
            ['type' => 'assign_round_robin', 'params' => ['target_column_id' => $owner->id, 'user_ids' => [$ada->id, $grace->id], 'strategy' => 'rotation']],
            ['type' => 'add_checklist_items', 'params' => ['target_column_id' => $list->id, 'tasks' => ['Kickoff', 'Kickoff', 'Review']]],
        ],
    ]);
    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'set_subitems_value', 'params' => ['target_column_id' => $sub_status->id, 'value' => 'sub_done']]],
    ]);
    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_SUBITEM_CREATED,
        'actions' => [['type' => 'set_parent_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']]],
    ]);

    $ids = collect(['One', 'Two', 'Three'])->map(fn (string $name) => $this->actingAs($actor, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => $name])
        ->assertCreated()
        ->json('item.id'));
    $assigned = $ids->map(fn ($id) => roundThreeValue(BoardItem::find($id), $owner)[0] ?? null)->all();
    expect($assigned)->toBe([(string) $ada->id, (string) $grace->id, (string) $ada->id]);
    expect(collect(roundThreeValue(BoardItem::find($ids[0]), $list))->pluck('text')->all())->toBe(['Kickoff', 'Review']);

    $parent = BoardItem::find($ids[0]);
    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items", ['parent_id' => $parent->id, 'name' => 'Child'])->assertCreated();
    expect(roundThreeValue($parent, $status))->toBe('working');

    roundThreeSet($actor, $board, $parent, $status, 'done');
    $child_item = BoardItem::where('parent_id', $parent->id)->first();
    expect(roundThreeValue($child_item, $sub_status))->toBe('sub_done');
});

test('moving a date shifts the items that depend on it, keeping the gap or counting working days', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    [$board, $view, $group] = roundThreeBoard();
    $actor = User::factory()->create();
    $timeline = roundThreeColumn($board, $view, BoardColumn::TYPE_TIMELINE, 'Timeline');
    $depends = roundThreeColumn($board, $view, BoardColumn::TYPE_DEPENDENCY, 'Depends on');
    $design = roundThreeItem($board, $group, 'Design');
    $build = roundThreeItem($board, $group, 'Build');
    BoardItemValue::create(['item_id' => $design->id, 'column_id' => $timeline->id, 'value' => ['start' => '2026-10-05', 'end' => '2026-10-09']]);
    BoardItemValue::create(['item_id' => $build->id, 'column_id' => $timeline->id, 'value' => ['start' => '2026-10-12', 'end' => '2026-10-16']]);
    BoardItemValue::create(['item_id' => $build->id, 'column_id' => $depends->id, 'value' => [(string) $design->id]]);

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_DATE_CHANGED,
        'trigger_column_id' => $timeline->id,
        'actions' => [['type' => 'shift_dependents', 'params' => ['target_column_id' => $timeline->id, 'dependency_column_id' => $depends->id, 'mode' => 'strict', 'use_working_days' => true]]],
    ]);

    // Design slips two working days, Thursday to Tuesday, so Build keeps its gap in working days.
    roundThreeSet($actor, $board, $design, $timeline, ['start' => '2026-10-07', 'end' => '2026-10-13']);
    expect(roundThreeValue($build, $timeline))->toBe(['start' => '2026-10-14', 'end' => '2026-10-20']);
});

test('working days change date actions and date triggers', function () {
    Carbon::setTestNow('2026-10-09 10:00:00'); // A Friday.
    [$board, $view, $group, $status] = roundThreeBoard();
    $due = roundThreeColumn($board, $view, BoardColumn::TYPE_DATE, 'Due');
    $follow_up = roundThreeColumn($board, $view, BoardColumn::TYPE_DATE, 'Follow up');
    BoardAutomationSetting::create(['board_id' => $board->id, 'workdays' => [1, 2, 3, 4, 5], 'holidays' => ['2026-10-12']]);
    $item = roundThreeItem($board, $group, 'Contract');
    // Due on Sunday, a reminder on the working day before it fires today, Friday.
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $due->id, 'value' => '2026-10-11']);

    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_DATE_ARRIVED,
        'trigger_column_id' => $due->id,
        'trigger_config' => ['offset_days' => 0, 'time' => '09:00', 'timezone' => 'UTC', 'working_days_only' => true],
        'actions' => [['type' => 'set_date', 'params' => ['target_column_id' => $follow_up->id, 'offset_days' => 1, 'use_working_days' => true]]],
    ]);

    expect(app(BoardAutomationService::class)->runDueDateTriggers())->toBe(1);
    // One working day after Friday skips the weekend and the Monday holiday.
    expect(roundThreeValue($item, $follow_up))->toBe('2026-10-13');
});

test('pausing every automation of a board stops them until resumed', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $note = roundThreeColumn($board, $view, BoardColumn::TYPE_TEXT, 'Note');
    $item = roundThreeItem($board, $group, 'Task');
    roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $note->id, 'value' => 'Changed']]],
    ]);

    $this->actingAs($actor, 'api')->putJson("/api/boards/{$board->id}/automations/settings", ['is_paused' => true, 'workdays' => [1, 2, 3, 4], 'holidays' => ['2026-12-25']])
        ->assertOk()->assertJsonPath('data.is_paused', true)->assertJsonPath('data.workdays', [1, 2, 3, 4]);

    roundThreeSet($actor, $board, $item, $status, 'working');
    expect(roundThreeValue($item, $note))->toBeNull();

    $this->actingAs($actor, 'api')->putJson("/api/boards/{$board->id}/automations/settings", ['is_paused' => false])->assertOk();
    roundThreeSet($actor, $board, $item, $status, 'done');
    expect(roundThreeValue($item, $note))->toBe('Changed');
});

test('messages read column tokens and emails reach the address in an email column', function () {
    Queue::fake();
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $email = roundThreeColumn($board, $view, BoardColumn::TYPE_EMAIL, 'Client email');
    $item = roundThreeItem($board, $group, 'Proposal');
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $email->id, 'value' => 'client@example.com']);

    $automation = roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'send_email', 'params' => ['email_column_id' => $email->id, 'email_addresses' => ['boss@example.com'], 'message' => 'Status is {column:'.$status->id.'}']]],
    ]);

    roundThreeSet($actor, $board, $item, $status, 'done');

    Queue::assertPushed(SendEmailJob::class, 2);
    $log = BoardAutomationRunLog::where('automation_id', $automation->id)->first();
    expect($log->message)->toContain('client@example.com')->toContain('boss@example.com');
    expect(app(BoardAutomationMessageRenderer::class)->render('Status is {column:'.$status->id.'}', $automation, $item->fresh(), null))->toBe('Status is Done');
});

test('a failed step can be retried from the run history and its steps share a run id', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $actor = User::factory()->create();
    $note = roundThreeColumn($board, $view, BoardColumn::TYPE_TEXT, 'Note');
    $other = roundThreeBoard();
    $item = roundThreeItem($board, $group, 'Order');

    $automation = roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'failure_alert' => 'none',
        'actions' => [
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $note->id, 'value' => 'Seen']],
            // Another workspace's board, so the owner check fails and the step is recorded as failed.
            ['type' => 'create_item', 'params' => ['target_board_id' => $other[0]->id, 'target_group_id' => $other[2]->id, 'item_name' => 'Copy']],
        ],
    ]);

    roundThreeSet($actor, $board, $item, $status, 'done');
    $logs = BoardAutomationRunLog::where('automation_id', $automation->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2);
    expect($logs[0]->run_uuid)->toBe($logs[1]->run_uuid);
    expect($logs[1]->status)->toBe('failed');
    expect($logs[1]->step_index)->toBe(1);

    $this->actingAs($actor, 'api')->getJson("/api/boards/{$board->id}/automations/runs/{$logs[0]->id}")
        ->assertOk()->assertJsonCount(2, 'data.steps')->assertJsonPath('data.can_retry', false);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/automations/runs/{$logs[1]->id}/retry")
        ->assertOk()->assertJsonPath('data.0.retry_of_id', $logs[1]->id);
    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/automations/runs/{$logs[0]->id}/retry")->assertStatus(422);
});

test('every save keeps a version that can be restored', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $user = User::factory()->create();

    $id = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'name' => 'First',
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertCreated()->json('automation.id');

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/automations/{$id}", [
        'name' => 'Second',
        'trigger_type' => 'item_created',
        'actions' => [['type' => 'delete_item', 'params' => []]],
    ])->assertOk();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/automations/{$id}", ['is_enabled' => false])->assertOk();

    $versions = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/{$id}/versions")->assertOk()->json('data');
    expect($versions)->toHaveCount(2);
    expect($versions[0]['changed_parts'])->toBe(['trigger', 'actions', 'details']);

    $first = BoardAutomationVersion::where('automation_id', $id)->where('version', 1)->first();
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/{$id}/versions/{$first->id}/restore")
        ->assertOk()->assertJsonPath('automation.name', 'First')->assertJsonPath('automation.trigger_type', 'status_changed');
    expect(BoardAutomationVersion::where('automation_id', $id)->count())->toBe(3);
});

test('automations are enabled, disabled and deleted in bulk and copied to another board', function () {
    $workspace = Workspace::factory()->create();
    [$board, $view, $group, $status] = roundThreeBoard($workspace);
    [$target, $target_view, $target_group, $target_status] = roundThreeBoard($workspace);
    $user = User::factory()->create();
    BoardGroup::factory()->create(['board_id' => $target->id, 'board_view_id' => $target_view->id, 'name' => 'Done items']);
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'Done items']);

    $move = roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'move_to_group', 'params' => ['target_group_id' => $done_group->id]]],
    ]);
    $archive = roundThreeAutomation($board, $view, ['trigger_type' => 'item_created', 'actions' => [['type' => 'archive_item', 'params' => []]]]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/bulk", ['automation_ids' => [$move->id, $archive->id], 'action' => 'disable'])->assertOk();
    expect($move->fresh()->is_enabled)->toBeFalse();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/copy", ['automation_ids' => [$move->id], 'target_board_id' => $target->id])->assertCreated();
    $copy = BoardAutomation::find($response->json('data.0.automation_id'));
    expect($copy->board_view_id)->toBe($target_view->id);
    expect($copy->is_enabled)->toBeFalse();
    expect($copy->trigger_column_id)->toBe($target_status->id);
    expect($copy->trigger_value)->toBe('done');
    expect($copy->resolvedActions()[0]['params']['target_group_id'])->toBe(BoardGroup::where('board_view_id', $target_view->id)->where('name', 'Done items')->value('id'));
    expect($response->json('data.0.unmapped'))->toBe([]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/bulk", ['automation_ids' => [$archive->id], 'action' => 'delete'])->assertOk();
    expect(BoardAutomation::find($archive->id))->toBeNull();
});

test('the builder validates the new parts of a definition', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $user = User::factory()->create();
    $base = ['view_id' => $view->id, 'trigger_type' => 'status_changed', 'trigger_column_id' => $status->id];

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'actions' => [
            ['type' => 'wait', 'params' => ['amount' => 20, 'unit' => 'days']],
            ['type' => 'wait', 'params' => ['amount' => 20, 'unit' => 'days']],
            ['type' => 'archive_item', 'params' => []],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['actions']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base, 'trigger_type' => 'number_threshold', 'trigger_column_id' => $status->id,
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertStatus(422)->assertJsonValidationErrors(['trigger_column_id']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'actions' => [['type' => 'send_email', 'params' => ['message' => 'Hi']]],
    ])->assertStatus(422)->assertJsonValidationErrors(['actions.0.params.notify_user_id']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id, 'trigger_type' => 'recurring', 'trigger_config' => ['schedule' => ['frequency' => 'daily', 'time' => '09:00']],
        'actions' => [['type' => 'create_group', 'params' => ['group_name' => 'Week']]],
        'else_actions' => [['type' => 'create_group', 'params' => ['group_name' => 'Other']]],
    ])->assertStatus(422)->assertJsonValidationErrors(['else_actions']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        ...$base,
        'conditions' => [],
        'condition_operator' => 'or',
        'condition_groups' => [['join_operator' => 'and', 'rules' => [['column_id' => (string) $status->id, 'condition' => 'is', 'values' => ['done']]]]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
        'else_actions' => [['type' => 'wait', 'params' => ['amount' => 1, 'unit' => 'hours']], ['type' => 'delete_item', 'params' => []]],
    ])->assertCreated()->assertJsonPath('automation.condition_operator', 'or')->assertJsonCount(2, 'automation.else_actions');
});

test('the item drawer lists the automations of the item and what ran on it', function () {
    [$board, $view, $group, $status] = roundThreeBoard();
    $user = User::factory()->create();
    $item = roundThreeItem($board, $group, 'Drawer item');
    $automation = roundThreeAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'conditions' => [['column_id' => 'name', 'condition' => 'contains', 'value' => 'Drawer', 'values' => []]],
        'actions' => [['type' => 'post_update', 'params' => ['message' => 'Hello']]],
        'owner_id' => $user->id,
    ]);
    roundThreeSet($user, $board, $item, $status, 'done');

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('data.automations.0.id', $automation->id)
        ->assertJsonPath('data.automations.0.passes_conditions', true)
        ->assertJsonCount(1, 'data.runs');
});
