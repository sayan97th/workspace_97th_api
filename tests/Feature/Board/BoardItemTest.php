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
