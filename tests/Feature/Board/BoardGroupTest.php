<?php

use App\Models\BoardGroup;
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
