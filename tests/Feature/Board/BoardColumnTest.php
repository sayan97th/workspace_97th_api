<?php

use App\Models\BoardColumn;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createColumnTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('a column can be created with a type-specific config', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'key' => 'status',
        'label' => 'Status',
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [['id' => 'done', 'label' => 'Done', 'color' => '#00c875']]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('column.key', 'status')
        ->assertJsonPath('column.type', BoardColumn::TYPE_STATUS)
        ->assertJsonPath('column.config.options.0.id', 'done');
});

test('a status column created without a config gets default options seeded', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'key' => 'stage',
        'label' => 'Stage',
        'type' => BoardColumn::TYPE_STATUS,
    ]);

    $response->assertCreated()
        ->assertJsonCount(3, 'column.config.options')
        ->assertJsonPath('column.config.options.0.label', 'Working on it');
});

test('a non-status column created without a config has no options', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'key' => 'notes',
        'label' => 'Notes',
        'type' => BoardColumn::TYPE_TEXT,
    ]);

    $response->assertCreated()->assertJsonPath('column.config', null);
});

test('an invalid column type is rejected', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'foo', 'label' => 'Foo', 'type' => 'not_a_type'])
        ->assertUnprocessable();
});

test('a column key must be unique per board', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'status']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_TEXT])
        ->assertUnprocessable();
});

test('a status column created without a config seeds active default options', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'stage', 'label' => 'Stage', 'type' => BoardColumn::TYPE_STATUS])
        ->assertCreated()
        ->assertJsonPath('column.config.options.0.is_active', true);
});

test('a status column\'s labels can be renamed, recolored, given a description, deactivated, and deleted', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875', 'is_active' => true],
            ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c', 'is_active' => true],
        ]],
    ]);

    // Rename + recolor + describe "done", deactivate "stuck".
    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/columns/{$column->id}", [
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Completed', 'color' => '#037f4c', 'is_active' => true, 'description' => 'Finished and reviewed'],
            ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c', 'is_active' => false],
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('column.config.options.0.label', 'Completed')
        ->assertJsonPath('column.config.options.0.color', '#037f4c')
        ->assertJsonPath('column.config.options.0.description', 'Finished and reviewed')
        ->assertJsonPath('column.config.options.1.is_active', false);

    // Delete "stuck" outright.
    $delete_response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/columns/{$column->id}", [
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Completed', 'color' => '#037f4c', 'is_active' => true, 'description' => 'Finished and reviewed'],
        ]],
    ]);

    $delete_response->assertOk()->assertJsonCount(1, 'column.config.options');
});

test('a column can be moved to a new position', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'position' => 0]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}/move", ['position' => 3])
        ->assertOk()
        ->assertJsonPath('column.position', 3);
});

test('two tabs on the same board can each have a column with the same key', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $tab_one = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $tab_two = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['view_id' => $tab_one->id, 'key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_TEXT])
        ->assertCreated();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['view_id' => $tab_two->id, 'key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_TEXT])
        ->assertCreated();

    $this->assertDatabaseHas('board_columns', ['board_view_id' => $tab_one->id, 'key' => 'status']);
    $this->assertDatabaseHas('board_columns', ['board_view_id' => $tab_two->id, 'key' => 'status']);
});

test('a column\'s key must still be unique within the same tab', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $tab->id, 'key' => 'status']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['view_id' => $tab->id, 'key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_TEXT])
        ->assertUnprocessable();
});

test('GET columns is scoped to the given tab, defaulting to the primary tab', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $primary = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $other_tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $primary->id, 'key' => 'primary_col']);
    BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $other_tab->id, 'key' => 'other_col']);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/columns")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'primary_col');

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/columns?view_id={$other_tab->id}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'other_col');
});
