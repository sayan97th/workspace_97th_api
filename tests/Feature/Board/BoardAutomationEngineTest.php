<?php

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup}
 */
function createAutomationTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return [$board, $view, $group];
}

test('an item_created automation notifies the configured person as soon as the item is created', function () {
    Queue::fake();

    [$board, $view, $group] = createAutomationTestBoard();
    $actor = User::factory()->create();
    $recipient = User::factory()->create();

    BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_ITEM_CREATED,
        'trigger_column_id' => null,
        'action_type' => BoardAutomation::ACTION_NOTIFY_PERSON,
        'action_params' => ['notify_user_id' => $recipient->id],
    ]);

    $this->actingAs($actor, 'api')->postJson("/api/boards/{$board->id}/items", [
        'group_id' => $group->id,
        'name' => 'A brand new task',
    ])->assertCreated();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $recipient->id,
        'type' => Notification::TYPE_AUTOMATION,
        'board_id' => $board->id,
    ]);
});

test('a person_assigned automation sets a status column once someone is newly assigned', function () {
    [$board, $view, $group] = createAutomationTestBoard();
    $actor = User::factory()->create();
    $assignee = User::factory()->create();

    $people_column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $status_column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [['id' => 'in_progress', 'label' => 'Working on it', 'color' => '#fdab3d']]],
    ]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Assigned task', 'position' => 0]);

    BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_PERSON_ASSIGNED,
        'trigger_column_id' => $people_column->id,
        'trigger_value' => null,
        'action_type' => BoardAutomation::ACTION_SET_COLUMN_VALUE,
        'action_params' => ['target_column_id' => $status_column->id, 'value' => 'in_progress'],
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $people_column->id => [$assignee->id]],
    ])->assertOk();

    $this->assertDatabaseHas('board_item_values', [
        'item_id' => $item->id,
        'column_id' => $status_column->id,
        'value' => json_encode('in_progress'),
    ]);
});

test('a status_changed automation archives the item once it matches the watched option', function () {
    [$board, $view, $group] = createAutomationTestBoard();
    $actor = User::factory()->create();

    $status_column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [['id' => 'done', 'label' => 'Done', 'color' => '#00c875']]],
    ]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task to archive', 'position' => 0]);

    BoardAutomation::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'is_enabled' => true,
        'trigger_type' => BoardAutomation::TRIGGER_STATUS_CHANGED,
        'trigger_column_id' => $status_column->id,
        'trigger_value' => 'done',
        'action_type' => BoardAutomation::ACTION_ARCHIVE_ITEM,
        'action_params' => [],
    ]);

    $this->actingAs($actor, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [(string) $status_column->id => 'done'],
    ])->assertOk();

    $this->assertSoftDeleted('board_items', ['id' => $item->id]);
});
