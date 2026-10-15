<?php

use App\Models\BoardGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Covers the board toolbar's "New item" split button: the main button puts a
 * row at the top of the first table, and its menu's "New group of items"
 * puts a new table at the top of the view.
 */
function createToolbarMenuBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'position' => 0]);

    return [$board, $group];
}

test('a new item at position 0 lands first and shifts only its own table down', function () {
    [$board, $group] = createToolbarMenuBoard();
    $user = User::factory()->create();
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'position' => 1]);
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);
    $other_table = $board->items()->create(['group_id' => $other_group->id, 'name' => 'Other table', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'New item', 'group_id' => $group->id, 'position' => 0])
        ->assertCreated()
        ->assertJsonPath('item.position', 0);

    expect($first->fresh()->position)->toBe(1);
    expect($second->fresh()->position)->toBe(2);
    expect($other_table->fresh()->position)->toBe(0);
});

test('a new item at position 0 leaves subitems alone', function () {
    [$board, $group] = createToolbarMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $subitem = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Subitem', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'New item', 'group_id' => $group->id, 'position' => 0])->assertCreated();

    expect($parent->fresh()->position)->toBe(1);
    expect($subitem->fresh()->position)->toBe(0);
});

test('a new item without a position is still appended', function () {
    [$board, $group] = createToolbarMenuBoard();
    $user = User::factory()->create();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Appended', 'group_id' => $group->id])
        ->assertCreated()
        ->assertJsonPath('item.position', 1);

    expect($first->fresh()->position)->toBe(0);
});

test('a new group of items at position 0 lands at the top of the view', function () {
    [$board, $group] = createToolbarMenuBoard();
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/groups", [
        'view_id' => $group->board_view_id,
        'name' => 'New group',
        'position' => 0,
    ])->assertCreated()->assertJsonPath('group.position', 0);

    expect($group->fresh()->position)->toBe(1);

    $first_group_id = BoardGroup::where('board_view_id', $group->board_view_id)->orderBy('position')->value('id');
    expect($first_group_id)->toBe($response->json('group.id'));
});
