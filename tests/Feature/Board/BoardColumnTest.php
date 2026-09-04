<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
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

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardGroup}
 */
function createColumnTestBoardWithGroup(): array
{
    $board = createColumnTestBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board, $group];
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

test('a dropdown column can be created and has no options by default', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", [
            'key' => 'dropdown',
            'label' => 'Dropdown',
            'type' => BoardColumn::TYPE_DROPDOWN,
        ])
        ->assertCreated()
        ->assertJsonPath('column.type', BoardColumn::TYPE_DROPDOWN)
        ->assertJsonPath('column.config', null);
});

test('a new option can be appended to a dropdown column inline, from its cell picker', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'type' => BoardColumn::TYPE_DROPDOWN,
        'config' => ['options' => []],
    ]);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/columns/{$column->id}", [
        'config' => ['options' => [
            ['id' => 'opt_1', 'label' => 'Design', 'color' => '#00c875'],
        ]],
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'column.config.options')
        ->assertJsonPath('column.config.options.0.label', 'Design');
});

test('a malformed option in config.options is rejected', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_DROPDOWN]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/columns/{$column->id}", [
        'config' => ['options' => [['label' => 'Missing id and color']]],
    ])->assertUnprocessable();
});

test('a column created at an explicit position shifts columns already there, landing right after the target', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $before = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'before', 'position' => 0]);
    $target = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'target', 'position' => 1]);
    $after = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'after', 'position' => 2]);

    // Mirrors the column menu's "Add column to the right": the frontend
    // resolves `target`'s position and asks for `target->position + 1`.
    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'key' => 'new_column',
        'label' => 'New column',
        'type' => BoardColumn::TYPE_TEXT,
        'position' => $target->position + 1,
    ]);

    $response->assertCreated()->assertJsonPath('column.position', 2);
    $this->assertDatabaseHas('board_columns', ['id' => $target->fresh()->id, 'position' => 1]);
    $this->assertDatabaseHas('board_columns', ['id' => $after->fresh()->id, 'position' => 3]);
    $this->assertDatabaseHas('board_columns', ['id' => $before->fresh()->id, 'position' => 0]);
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

test('a label column created without a config gets the priority palette seeded', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'priority', 'label' => 'Priority', 'type' => BoardColumn::TYPE_LABEL])
        ->assertCreated()
        ->assertJsonCount(4, 'column.config.options')
        ->assertJsonPath('column.config.options.0.label', 'Low');
});

test('a progress column created without a config has no options (a plain 0-100 number under the hood)', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'progress', 'label' => 'Progress', 'type' => BoardColumn::TYPE_PROGRESS])
        ->assertCreated()
        ->assertJsonPath('column.config', null);
});

test('a column can be duplicated without its values, landing right after the original', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $before = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'before', 'position' => 0]);
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'status', 'type' => BoardColumn::TYPE_TEXT, 'position' => 1]);
    $after = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'after', 'position' => 2]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns/{$column->id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('column.label', "{$column->label} copy")
        ->assertJsonPath('column.position', 2);
    $this->assertDatabaseHas('board_columns', ['id' => $after->fresh()->id, 'position' => 3]);
    $this->assertDatabaseHas('board_columns', ['id' => $before->fresh()->id, 'position' => 0]);
});

test('duplicating a column with values copies every item\'s stored value for it', function () {
    [$board, $group] = createColumnTestBoardWithGroup();
    $user = User::factory()->create();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_TEXT]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $item->values()->create(['column_id' => $column->id, 'value' => 'hello']);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns/{$column->id}/duplicate", ['with_values' => true]);

    $response->assertCreated();
    $copy_id = $response->json('column.id');
    $this->assertDatabaseHas('board_item_values', ['item_id' => $item->id, 'column_id' => $copy_id, 'value' => json_encode('hello')]);
});

test('duplicating a column\'s key never collides with an existing key in the same tab+scope', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'key' => 'notes'])->fresh();
    BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $column->board_view_id, 'scope' => $column->scope, 'key' => 'notes_copy']);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns/{$column->id}/duplicate");

    $response->assertCreated()->assertJsonPath('column.key', 'notes_copy_2');
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
