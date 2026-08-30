<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createItemTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board, $group];
}

test('an item can be created with initial column values', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", [
        'name' => 'Launch campaign',
        'group_id' => $group->id,
        'values' => [(string) $column->id => 'in_progress'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('item.name', 'Launch campaign')
        ->assertJsonPath("item.values.{$column->id}", 'in_progress');
});

test('the items index search matches on item name and column values', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $notes_column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_TEXT]);

    $matching = $board->items()->create(['group_id' => $group->id, 'name' => 'Redesign homepage', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Unrelated task', 'position' => 1]);
    $value_match = $board->items()->create(['group_id' => $group->id, 'name' => 'Another task', 'position' => 2]);
    $value_match->values()->create(['column_id' => $notes_column->id, 'value' => 'mentions homepage in notes']);

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items?search=homepage");

    $response->assertOk()->assertJsonCount(2, 'data');
    expect(collect($response->json('data'))->pluck('id'))
        ->toContain($matching->id, $value_match->id);
});

test('inline cell edits upsert a value and ignore columns from other boards', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_TEXT]);
    $foreign_column = BoardColumn::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [
            (string) $column->id => 'updated text',
            (string) $foreign_column->id => 'should be ignored',
        ],
    ]);

    $response->assertOk()->assertJsonPath("item.values.{$column->id}", 'updated text');
    $this->assertDatabaseHas('board_item_values', ['item_id' => $item->id, 'column_id' => $column->id, 'value' => json_encode('updated text')]);
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $foreign_column->id]);
});

test('an item detail response includes group and creator for the pulse drawer', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0, 'created_by_id' => $user->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items/{$item->id}")
        ->assertOk()
        ->assertJsonPath('group.id', $group->id)
        ->assertJsonPath('creator.id', $user->id);
});

test('deleting an item soft-deletes it, excluding it from the index', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/items/{$item->id}")
        ->assertOk();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('board_items', ['id' => $item->id]);
});

test('items are scoped per tab — an item is only returned for the tab its group belongs to', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $primary_tab = $group->boardView;
    $other_tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $other_tab->id]);

    $primary_item = $board->items()->create(['group_id' => $group->id, 'name' => 'Primary tab task', 'position' => 0]);
    $board->items()->create(['group_id' => $other_group->id, 'name' => 'Other tab task', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items?view_id={$primary_tab->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $primary_item->id);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items?view_id={$other_tab->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Other tab task');
});

test('bulk duplicate copies name, description and values into the same group', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_TEXT]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'description' => 'Notes', 'position' => 0]);
    $item->values()->create(['column_id' => $column->id, 'value' => 'hello']);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", [
        'item_ids' => [$item->id],
    ]);

    $response->assertCreated()->assertJsonCount(1, 'items');
    $duplicate_id = $response->json('items.0.id');
    expect($duplicate_id)->not->toBe($item->id);
    $response->assertJsonPath('items.0.name', 'Task (copy)')
        ->assertJsonPath('items.0.group_id', $group->id)
        ->assertJsonPath("items.0.values.{$column->id}", 'hello');
    $this->assertDatabaseHas('board_items', ['id' => $duplicate_id, 'description' => 'Notes']);
});

test('bulk move updates the group of every given item', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $target_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/move", [
        'item_ids' => [$first->id, $second->id],
        'group_id' => $target_group->id,
    ]);

    $response->assertOk()->assertJsonCount(2, 'items');
    $this->assertDatabaseHas('board_items', ['id' => $first->id, 'group_id' => $target_group->id]);
    $this->assertDatabaseHas('board_items', ['id' => $second->id, 'group_id' => $target_group->id]);
});

test('bulk move rejects a target group from another board', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    [$other_board] = createItemTestBoard();
    $foreign_group = BoardGroup::factory()->create(['board_id' => $other_board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/move", [
        'item_ids' => [$item->id],
        'group_id' => $foreign_group->id,
    ])->assertInvalid(['group_id']);
});

test('bulk archive hides items from the index without soft-deleting them', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", [
        'item_ids' => [$item->id],
    ])->assertOk();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items")
        ->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseHas('board_items', ['id' => $item->id, 'is_archived' => true, 'deleted_at' => null]);
});

test('bulk delete soft-deletes every given item', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items", [
        'item_ids' => [$first->id, $second->id],
    ])->assertOk();

    $this->assertSoftDeleted('board_items', ['id' => $first->id]);
    $this->assertSoftDeleted('board_items', ['id' => $second->id]);
});

test('bulk actions reject item ids from another board', function () {
    [$board] = createItemTestBoard();
    $user = User::factory()->create();
    [$other_board, $other_group] = createItemTestBoard();
    $foreign_item = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", [
        'item_ids' => [$foreign_item->id],
    ])->assertInvalid(['item_ids.0']);
});

test('a subitem inherits its parent group and is excluded from the top-level index', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $parent = $board->items()->create(['group_id' => $other_group->id, 'name' => 'Parent', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", [
        'name' => 'Subitem',
        'parent_id' => $parent->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('item.parent_id', $parent->id)
        ->assertJsonPath('item.group_id', $other_group->id);

    $index = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk();
    $index->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.subitem_count', 1)
        ->assertJsonCount(1, 'data.0.children')
        ->assertJsonPath('data.0.children.0.name', 'Subitem');
});

test('subitems nest to unlimited depth in the index response', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $root = $board->items()->create(['group_id' => $group->id, 'name' => 'Root', 'position' => 0]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $root->id, 'name' => 'Child', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'parent_id' => $child->id, 'name' => 'Grandchild', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonPath('data.0.children.0.name', 'Child')
        ->assertJsonPath('data.0.children.0.children.0.name', 'Grandchild');
});

test('creating a subitem requires the parent to belong to the same board', function () {
    [$board] = createItemTestBoard();
    $user = User::factory()->create();
    [$other_board, $other_group] = createItemTestBoard();
    $foreign_parent = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", [
        'name' => 'Subitem',
        'parent_id' => $foreign_parent->id,
    ])->assertInvalid(['parent_id']);
});

test('deleting a parent item cascades to its subitems', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/items/{$parent->id}")
        ->assertOk();

    $this->assertSoftDeleted('board_items', ['id' => $parent->id]);
    $this->assertSoftDeleted('board_items', ['id' => $child->id]);
});

test('bulk delete cascades to subitems of the given items', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items", [
        'item_ids' => [$parent->id],
    ])->assertOk();

    $this->assertSoftDeleted('board_items', ['id' => $parent->id]);
    $this->assertSoftDeleted('board_items', ['id' => $child->id]);
});

test('moving a parent item cascades its new group onto every subitem', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $target_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/move", [
        'item_ids' => [$parent->id],
        'group_id' => $target_group->id,
    ])->assertOk();

    $this->assertDatabaseHas('board_items', ['id' => $parent->id, 'group_id' => $target_group->id]);
    $this->assertDatabaseHas('board_items', ['id' => $child->id, 'group_id' => $target_group->id]);
});

test('bulk duplicate deep-copies a parent item and its subitems', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", [
        'item_ids' => [$parent->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('items.0.name', 'Parent (copy)')
        ->assertJsonCount(1, 'items.0.children')
        ->assertJsonPath('items.0.children.0.name', 'Child');
});

test('inline cell edits ignore a column that belongs to a different tab of the same board', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_TEXT]);

    $other_tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    $column_from_other_tab = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $other_tab->id, 'type' => BoardColumn::TYPE_TEXT]);

    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", [
        'values' => [
            (string) $column->id => 'updated text',
            (string) $column_from_other_tab->id => 'should be ignored',
        ],
    ]);

    $response->assertOk()->assertJsonPath("item.values.{$column->id}", 'updated text');
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $column_from_other_tab->id]);
});

test('reorder resequences root items within the same table', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);
    $third = $board->items()->create(['group_id' => $group->id, 'name' => 'Third', 'position' => 2]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'root',
        'moved_item_id' => $third->id,
        'target_group_id' => $group->id,
        'target_ordered_ids' => [$third->id, $first->id, $second->id],
    ])->assertOk()->assertJsonCount(3, 'items');

    $this->assertDatabaseHas('board_items', ['id' => $third->id, 'position' => 0]);
    $this->assertDatabaseHas('board_items', ['id' => $first->id, 'position' => 1]);
    $this->assertDatabaseHas('board_items', ['id' => $second->id, 'position' => 2]);
});

test('reorder moves a root item into a different table at an arbitrary position and resequences both tables', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $target_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $dragged = $board->items()->create(['group_id' => $group->id, 'name' => 'Dragged', 'position' => 0]);
    $remaining = $board->items()->create(['group_id' => $group->id, 'name' => 'Remaining', 'position' => 1]);
    $existing = $board->items()->create(['group_id' => $target_group->id, 'name' => 'Existing', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'root',
        'moved_item_id' => $dragged->id,
        'target_group_id' => $target_group->id,
        'target_ordered_ids' => [$dragged->id, $existing->id],
        'source_group_id' => $group->id,
        'source_ordered_ids' => [$remaining->id],
    ])->assertOk();

    $this->assertDatabaseHas('board_items', ['id' => $dragged->id, 'group_id' => $target_group->id, 'position' => 0]);
    $this->assertDatabaseHas('board_items', ['id' => $existing->id, 'group_id' => $target_group->id, 'position' => 1]);
    $this->assertDatabaseHas('board_items', ['id' => $remaining->id, 'group_id' => $group->id, 'position' => 0]);
});

test('reorder moving a parent item across tables cascades the new group onto its subitems', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $target_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'root',
        'moved_item_id' => $parent->id,
        'target_group_id' => $target_group->id,
        'target_ordered_ids' => [$parent->id],
    ])->assertOk();

    $this->assertDatabaseHas('board_items', ['id' => $parent->id, 'group_id' => $target_group->id]);
    $this->assertDatabaseHas('board_items', ['id' => $child->id, 'group_id' => $target_group->id]);
});

test('reorder resequences subitems within their shared parent', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $first_child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'First', 'position' => 0]);
    $second_child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Second', 'position' => 1]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'subitem',
        'target_parent_id' => $parent->id,
        'target_ordered_ids' => [$second_child->id, $first_child->id],
    ])->assertOk();

    $this->assertDatabaseHas('board_items', ['id' => $second_child->id, 'position' => 0]);
    $this->assertDatabaseHas('board_items', ['id' => $first_child->id, 'position' => 1]);
});

test('reorder rejects an id that does not actually belong to the target table', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    [$other_board, $other_group] = createItemTestBoard();
    $foreign_item = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'root',
        'moved_item_id' => $item->id,
        'target_group_id' => $group->id,
        'target_ordered_ids' => [$item->id, $foreign_item->id],
    ])->assertInvalid(['target_ordered_ids.1']);
});

test('reorder rejects a subitem id claimed under the wrong parent', function () {
    [$board, $group] = createItemTestBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $other_parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Other parent', 'position' => 1]);
    $child = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/reorder", [
        'scope' => 'subitem',
        'target_parent_id' => $other_parent->id,
        'target_ordered_ids' => [$child->id],
    ])->assertInvalid(['target_ordered_ids.0']);
});
