<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup}
 */
function createItemIdTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true, 'label' => 'Main table']);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return [$board, $view, $group];
}

test('a new item shows its own id in the Item ID column', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createItemIdTestBoard();
    $item_id_column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_AUTO_NUMBER]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Task', 'group_id' => $group->id]);

    $response->assertCreated();
    $item_id = $response->json('item.id');
    $response->assertJsonPath("item.values.{$item_id_column->id}", $item_id);
});

test('adding an Item ID column fills it for the items already on the board', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createItemIdTestBoard();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $first->id, 'name' => 'Sub', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'view_id' => $view->id,
        'key' => 'item_id',
        'label' => 'Item ID',
        'type' => BoardColumn::TYPE_AUTO_NUMBER,
    ]);

    $response->assertCreated();
    $column_id = $response->json('column.id');
    $this->assertDatabaseHas('board_item_values', ['item_id' => $first->id, 'column_id' => $column_id, 'value' => json_encode($first->id)]);
    $this->assertDatabaseHas('board_item_values', ['item_id' => $second->id, 'column_id' => $column_id, 'value' => json_encode($second->id)]);
    // An item scoped column leaves subitems alone.
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $sub->id, 'column_id' => $column_id]);
});

test('turning a column into an Item ID column replaces its values with the item ids', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createItemIdTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_NUMBER]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $item->values()->create(['column_id' => $column->id, 'value' => 7]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['type' => BoardColumn::TYPE_AUTO_NUMBER])
        ->assertOk();

    $this->assertDatabaseHas('board_item_values', ['item_id' => $item->id, 'column_id' => $column->id, 'value' => json_encode($item->id)]);
});
