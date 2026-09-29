<?php

use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationTemplate;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use Illuminate\Support\Carbon;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function createBuilderTestBoard(?Workspace $workspace = null): array
{
    $workspace ??= Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
            ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c'],
        ]],
    ]);

    return [$board, $view, $group, $status];
}

function builderAutomation(WorkspaceNavigationItem $board, BoardView $view, array $attributes): BoardAutomation
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

function setItemValue(User $actor, WorkspaceNavigationItem $board, BoardItem $item, BoardColumn $column, mixed $value): void
{
    test()->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $column->id => $value],
    ])->assertOk();
}

test('the builder creates an automation with conditions and several actions, and the first action is mirrored', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $user = User::factory()->create();
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'importance' => 'major',
        'trigger_type' => 'status_changed',
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'trigger_config' => ['from_value' => 'working'],
        'conditions' => [['column_id' => 'name', 'condition' => 'contains', 'value' => 'invoice', 'values' => []]],
        'actions' => [
            ['type' => 'notify_person', 'params' => ['notify_user_id' => $user->id, 'message' => '{item_name} is done']],
            ['type' => 'move_to_group', 'params' => ['target_group_id' => $done_group->id]],
        ],
    ])->assertCreated();

    $response->assertJsonPath('automation.importance', 'major')
        ->assertJsonPath('automation.actions.1.type', 'move_to_group')
        ->assertJsonPath('automation.action_type', 'notify_person')
        ->assertJsonPath('automation.trigger_config.from_value', 'working')
        ->assertJsonPath('automation.owner.id', $user->id);
});

test('the builder rejects actions whose params do not fit their type', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'item_created',
        'actions' => [
            ['type' => 'adjust_number', 'params' => ['target_column_id' => $status->id, 'amount' => 2]],
            ['type' => 'create_subitem', 'params' => ['subitem_names' => []]],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.params.target_column_id', 'actions.1.params.subitem_names']);
});

test('a recurring automation must start with an action that needs no item', function () {
    [$board, $view, $group] = createBuilderTestBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations", [
        'view_id' => $view->id,
        'trigger_type' => 'recurring',
        'trigger_config' => ['schedule' => ['frequency' => 'weekly', 'weekdays' => [1], 'time' => '09:00']],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.type']);
});

test('status from X to Y only fires for that exact change and runs every action in order', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $done_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'trigger_config' => ['from_value' => 'working'],
        'actions' => [
            ['type' => 'notify_person', 'params' => ['notify_user_id' => $recipient->id]],
            ['type' => 'move_to_group', 'params' => ['target_group_id' => $done_group->id]],
        ],
    ]);

    setItemValue($actor, $board, $item, $status, 'stuck');
    setItemValue($actor, $board, $item, $status, 'done');
    expect($item->fresh()->group_id)->toBe($group->id);

    setItemValue($actor, $board, $item, $status, 'working');
    setItemValue($actor, $board, $item, $status, 'done');
    expect($item->fresh()->group_id)->toBe($done_group->id);
    expect(Notification::where('user_id', $recipient->id)->count())->toBe(1);
    expect(BoardAutomationRunLog::where('status', 'success')->pluck('action_type')->all())->toBe(['notify_person', 'move_to_group']);
});

test('conditions that are not met skip the actions and are recorded', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $actor = User::factory()->create();
    $priority = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_NUMBER]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Low value', 'position' => 0]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'conditions' => [['column_id' => (string) $priority->id, 'condition' => 'greater_than', 'value' => '10', 'values' => []]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ]);

    setItemValue($actor, $board, $item, $priority, 5);
    setItemValue($actor, $board, $item, $status, 'done');
    expect($item->fresh()->is_archived)->toBeFalse();
    expect(BoardAutomationRunLog::latest('id')->first()->message)->toBe('The conditions were not met.');

    setItemValue($actor, $board, $item, $status, 'working');
    setItemValue($actor, $board, $item, $priority, 50);
    setItemValue($actor, $board, $item, $status, 'done');
    expect($item->fresh()->is_archived)->toBeTrue();
});

test('two automations that set each other off are stopped instead of looping forever', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $actor = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Ping pong', 'position' => 0]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'stuck']]],
    ]);
    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'stuck',
        'actions' => [['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'done']]],
    ]);

    setItemValue($actor, $board, $item, $status, 'done');

    expect(BoardAutomationRunLog::where('status', 'skipped')->where('message', 'like', 'Skipped to prevent a loop%')->exists())->toBeTrue();
    expect(BoardAutomationRunLog::count())->toBeLessThan(10);
});

test('item actions create subitems, assign the creator, adjust a number and set a date', function () {
    Carbon::setTestNow('2026-09-28 10:00:00');
    [$board, $view, $group] = createBuilderTestBoard();
    $actor = User::factory()->create();
    $people = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $number = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_NUMBER]);
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'actions' => [
            ['type' => 'create_subitem', 'params' => ['subitem_names' => ['Plan', 'Build']]],
            ['type' => 'assign_person', 'params' => ['target_column_id' => $people->id, 'assign_mode' => 'creator']],
            ['type' => 'adjust_number', 'params' => ['target_column_id' => $number->id, 'amount' => 3]],
            ['type' => 'set_date', 'params' => ['target_column_id' => $date->id, 'offset_days' => 7]],
        ],
    ]);

    $item_id = $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Launch'])
        ->assertCreated()->json('item.id');
    $item = BoardItem::with('values')->find($item_id);

    expect(BoardItem::where('parent_id', $item_id)->orderBy('position')->pluck('name')->all())->toBe(['Plan', 'Build']);
    expect($item->values->firstWhere('column_id', $people->id)->value)->toBe([(string) $actor->id]);
    expect($item->values->firstWhere('column_id', $number->id)->value)->toBe(3);
    expect($item->values->firstWhere('column_id', $date->id)->value)->toBe('2026-10-05');
    Carbon::setTestNow();
});

test('moving an item to a group fires the moved trigger and posting an update is written by the owner', function () {
    [$board, $view, $group] = createBuilderTestBoard();
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $review = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Draft', 'position' => 0]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_MOVED_TO_GROUP,
        'trigger_config' => ['group_id' => $review->id],
        'owner_id' => $owner->id,
        'actions' => [['type' => 'post_update', 'params' => ['message' => '{item_name} is ready for review']]],
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/move", [
        'item_ids' => [$item->id],
        'group_id' => $review->id,
    ])->assertOk();

    $comment = BoardItemComment::where('item_id', $item->id)->first();
    expect($comment?->body)->toBe('Draft is ready for review');
    expect($comment?->user_id)->toBe($owner->id);
});

test('date triggers fire before the date with an offset, once a day, after their time', function () {
    [$board, $view, $group] = createBuilderTestBoard();
    $recipient = User::factory()->create();
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Deadline soon', 'position' => 0]);
    $item->values()->create(['column_id' => $date->id, 'value' => '2026-10-01']);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_DATE_ARRIVED,
        'trigger_column_id' => $date->id,
        'trigger_config' => ['offset_days' => -3, 'time' => '09:00', 'timezone' => 'UTC'],
        'actions' => [['type' => 'notify_person', 'params' => ['notify_user_id' => $recipient->id]]],
    ]);

    $service = app(BoardAutomationService::class);
    expect($service->runDueDateTriggers(Carbon::parse('2026-09-28 08:30', 'UTC')))->toBe(0);
    expect($service->runDueDateTriggers(Carbon::parse('2026-09-28 09:05', 'UTC')))->toBe(1);
    expect($service->runDueDateTriggers(Carbon::parse('2026-09-28 12:00', 'UTC')))->toBe(0);
    expect(Notification::where('user_id', $recipient->id)->count())->toBe(1);
});

test('a recurring automation creates an item on schedule and later actions act on it', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();

    $automation = builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_RECURRING,
        'trigger_config' => ['schedule' => ['frequency' => 'weekly', 'weekdays' => [1], 'time' => '09:00', 'timezone' => 'UTC']],
        'last_scheduled_run_at' => Carbon::parse('2026-09-27 12:00', 'UTC'),
        'actions' => [
            ['type' => 'create_item', 'params' => ['target_group_id' => $group->id, 'item_name' => 'Weekly report {date}']],
            ['type' => 'set_column_value', 'params' => ['target_column_id' => $status->id, 'value' => 'working']],
        ],
    ]);

    $service = app(BoardAutomationService::class);
    // 2026-09-28 is a Monday.
    expect($service->runScheduledTriggers(Carbon::parse('2026-09-28 08:00', 'UTC')))->toBe(0);
    Carbon::setTestNow('2026-09-28 09:01');
    expect($service->runScheduledTriggers(Carbon::parse('2026-09-28 09:01', 'UTC')))->toBe(1);
    expect($service->runScheduledTriggers(Carbon::parse('2026-09-28 09:30', 'UTC')))->toBe(0);
    Carbon::setTestNow();

    $created = BoardItem::with('values')->where('group_id', $group->id)->first();
    expect($created->name)->toBe('Weekly report 2026-09-28');
    expect($created->values->firstWhere('column_id', $status->id)?->value)->toBe('working');
    expect($automation->fresh()->last_scheduled_run_at)->not->toBeNull();
});

test('a cross board automation creates a copy of the item on another board with matching values', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    [$other_board, $other_view, $other_group, $other_status] = createBuilderTestBoard(Workspace::find($board->workspace_id));
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Approved request', 'position' => 0]);

    builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'owner_id' => $owner->id,
        'actions' => [['type' => 'create_item', 'params' => [
            'target_board_id' => $other_board->id,
            'target_group_id' => $other_group->id,
            'item_name' => 'Follow up: {item_name}',
            'copy_values' => true,
        ]]],
    ]);

    setItemValue($actor, $board, $item, $status, 'done');

    $copy = BoardItem::with('values')->where('board_id', $other_board->id)->first();
    expect($copy?->name)->toBe('Follow up: Approved request');
    expect($copy?->values->firstWhere('column_id', $other_status->id)?->value)->toBe('done');
});

test('a failed run notifies the owner once, and templates and ownership can be managed', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $user = User::factory()->create();
    $new_owner = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $automation = builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'name' => 'Broken move',
        'created_by_id' => $user->id,
        'actions' => [['type' => 'move_to_board', 'params' => ['target_board_id' => 999999, 'target_group_id' => 1]]],
    ]);

    setItemValue($new_owner, $board, $item, $status, 'done');
    setItemValue($new_owner, $board, $item, $status, 'working');
    setItemValue($new_owner, $board, $item, $status, 'done');
    expect(Notification::where('user_id', $user->id)->where('type', Notification::TYPE_AUTOMATION)->count())->toBe(1);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/automations/{$automation->id}", ['owner_id' => $new_owner->id, 'importance' => 'critical'])
        ->assertOk()->assertJsonPath('automation.owner.id', $new_owner->id)->assertJsonPath('automation.importance', 'critical');

    $template_id = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/automations/{$automation->id}/template", ['name' => 'Reusable'])
        ->assertCreated()->assertJsonPath('template.definition.actions.0.type', 'move_to_board')->json('template.id');

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/automations/templates")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/automations/templates/{$template_id}")->assertOk();
    expect(BoardAutomationTemplate::count())->toBe(0);
});

test('editing an automation from the builder replaces its whole definition', function () {
    [$board, $view, $group, $status] = createBuilderTestBoard();
    $user = User::factory()->create();

    $automation = builderAutomation($board, $view, [
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status->id,
        'trigger_value' => 'done',
        'conditions' => [['column_id' => 'name', 'condition' => 'contains', 'value' => 'x', 'values' => []]],
        'actions' => [['type' => 'archive_item', 'params' => []]],
    ]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/automations/{$automation->id}", [
        'trigger_type' => 'item_created',
        'trigger_column_id' => null,
        'trigger_value' => null,
        'trigger_config' => null,
        'conditions' => [],
        'actions' => [['type' => 'duplicate_item', 'params' => ['with_subitems' => false]]],
    ])->assertOk();

    $automation->refresh();
    expect($automation->trigger_type)->toBe('item_created');
    expect($automation->trigger_column_id)->toBeNull();
    expect($automation->conditions)->toBeNull();
    expect($automation->action_type)->toBe('duplicate_item');
});
