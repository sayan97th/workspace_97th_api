<?php

use App\Models\BoardColumn;
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

test('a column can be moved to a new position', function () {
    $user = User::factory()->create();
    $board = createColumnTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'position' => 0]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}/move", ['position' => 3])
        ->assertOk()
        ->assertJsonPath('column.position', 3);
});
