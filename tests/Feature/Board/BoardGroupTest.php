<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createGroupTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);

    return $board;
}

test('a board can list any number of groups (tables)', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    BoardGroup::factory()->count(5)->create(['board_id' => $board->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/groups")
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

test('a group can be created on a board', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/groups", ['name' => 'Backlog', 'accent_color' => '#579bfc'])
        ->assertCreated()
        ->assertJsonPath('group.name', 'Backlog')
        ->assertJsonPath('group.accent_color', '#579bfc');

    $this->assertDatabaseHas('board_groups', ['board_id' => $board->id, 'name' => 'Backlog']);
});

test('a group belonging to a different board cannot be updated', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $other_board = createGroupTestBoard();
    $group = BoardGroup::factory()->create(['board_id' => $other_board->id]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['name' => 'Hacked'])
        ->assertNotFound();
});

test('deleting a group cascades to its items', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/groups/{$group->id}")
        ->assertOk();

    $this->assertDatabaseMissing('board_groups', ['id' => $group->id]);
    $this->assertDatabaseMissing('board_items', ['id' => $item->id]);
});

test('a group can be duplicated without its items', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Backlog', 'accent_color' => '#579bfc']);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/groups/{$group->id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('group.name', 'Backlog copy')
        ->assertJsonPath('group.accent_color', '#579bfc');
    $copy_id = $response->json('group.id');
    expect($copy_id)->not->toBe($group->id);
    $this->assertDatabaseCount('board_items', 1);
    $this->assertDatabaseHas('board_items', ['id' => $item->id, 'group_id' => $group->id]);
});

test('a group can be duplicated with its items, deep-copying subitems and values', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Backlog']);
    $column = $board->columns()->create(['board_view_id' => $group->board_view_id, 'key' => 'status', 'label' => 'Status', 'type' => 'status', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);
    $item->values()->create(['column_id' => $column->id, 'value' => 'done']);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $item->id, 'name' => 'Subtask 1', 'position' => 0]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/groups/{$group->id}/duplicate", ['with_items' => true]);

    $response->assertCreated();
    $copy_group_id = $response->json('group.id');

    $this->assertDatabaseHas('board_items', ['group_id' => $copy_group_id, 'name' => 'Task 1', 'parent_id' => null]);
    $copied_item_id = BoardItem::where('group_id', $copy_group_id)->whereNull('parent_id')->value('id');
    $this->assertDatabaseHas('board_item_values', ['item_id' => $copied_item_id, 'column_id' => $column->id, 'value' => json_encode('done')]);
    $this->assertDatabaseHas('board_items', ['group_id' => $copy_group_id, 'name' => 'Subtask 1', 'parent_id' => $copied_item_id]);
    // Original items untouched.
    $this->assertDatabaseHas('board_items', ['id' => $item->id, 'group_id' => $group->id]);
    $this->assertDatabaseHas('board_items', ['id' => $sub->id, 'group_id' => $group->id]);
});

test('groups are scoped per tab — two tabs on the same board return disjoint groups', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $tab_one = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $tab_two = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $tab_one->id, 'name' => 'Tab one table']);
    BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $tab_two->id, 'name' => 'Tab two table']);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/groups?view_id={$tab_one->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Tab one table');

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/groups?view_id={$tab_two->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Tab two table');
});

test('a group created for a specific tab is persisted against that tab', function () {
    $user = User::factory()->create();
    $board = createGroupTestBoard();
    $tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/groups", ['view_id' => $tab->id, 'name' => 'Backlog'])
        ->assertCreated();

    $this->assertDatabaseHas('board_groups', ['board_id' => $board->id, 'board_view_id' => $tab->id, 'name' => 'Backlog']);
});
