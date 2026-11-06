<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup}
 */
function createPeopleAutoAssignTestBoard(): array
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

function createReporterColumn(WorkspaceNavigationItem $board, BoardView $view, bool $auto_assign_creator = true): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_PEOPLE,
        'label' => 'Reporter',
        'config' => ['auto_assign_creator' => $auto_assign_creator],
    ]);
}

test('a new item gets its creator in an auto assign People column', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['name' => 'Bug report', 'group_id' => $group->id])
        ->assertCreated()
        ->assertJsonPath("item.values.{$column->id}", [(string) $user->id]);
});

test('a new subitem gets its creator in an auto assign subitem People column', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view);
    $column->update(['scope' => BoardColumn::SCOPE_SUBITEM]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['name' => 'Step', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath("item.values.{$column->id}", [(string) $user->id]);
});

test('people sent with the new item win over the creator', function () {
    $user = User::factory()->create();
    $teammate = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", [
            'name' => 'Bug report',
            'group_id' => $group->id,
            'values' => [(string) $column->id => [(string) $teammate->id]],
        ])
        ->assertCreated()
        ->assertJsonPath("item.values.{$column->id}", [(string) $teammate->id]);
});

test('the auto assigned creator can be changed like any People cell', function () {
    $user = User::factory()->create();
    $teammate = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view);
    $item_id = $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['name' => 'Bug report', 'group_id' => $group->id])
        ->json('item.id');

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item_id}/values", ['values' => [(string) $column->id => [(string) $teammate->id]]])
        ->assertOk()
        ->assertJsonPath("item.values.{$column->id}", [(string) $teammate->id]);
});

test('the creator is not notified about their own auto assignment', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    createReporterColumn($board, $view);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['name' => 'Bug report', 'group_id' => $group->id])
        ->assertCreated();

    $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'type' => Notification::TYPE_ASSIGNED]);
});

test('a People column without auto assign stays empty on new items', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view, false);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Task', 'group_id' => $group->id]);

    $response->assertCreated();
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $response->json('item.id'), 'column_id' => $column->id]);
});

test('the setting is saved on a People column', function () {
    $user = User::factory()->create();
    [$board, $view] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view, false);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['config' => ['auto_assign_creator' => true]])
        ->assertOk()
        ->assertJsonPath('column.config.auto_assign_creator', true);
});

test('creating a Reporter column assigns each existing item creator right away', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $known = $board->items()->create(['group_id' => $group->id, 'name' => 'Known', 'position' => 0, 'created_by_id' => $author->id]);
    $unknown = $board->items()->create(['group_id' => $group->id, 'name' => 'Imported', 'position' => 1]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/columns", [
        'view_id' => $view->id,
        'key' => 'reporter_1',
        'label' => 'Reporter',
        'type' => BoardColumn::TYPE_PEOPLE,
        'config' => ['auto_assign_creator' => true],
    ]);

    $response->assertCreated()
        ->assertJsonPath('column.config.auto_assign_creator', true)
        ->assertJsonPath("filled_values.{$known->id}", [(string) $author->id])
        ->assertJsonMissingPath("filled_values.{$unknown->id}");
});

test('turning a Creation log column into a People column keeps the creators and keeps assigning them', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_CREATION_LOG]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Old', 'position' => 0, 'created_by_id' => $author->id]);
    // A date kept from when the column was a Date column is not a person, so it is replaced.
    $item->values()->create(['column_id' => $column->id, 'value' => '2025-12-01']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['type' => BoardColumn::TYPE_PEOPLE])
        ->assertOk()
        ->assertJsonPath('column.type', BoardColumn::TYPE_PEOPLE)
        ->assertJsonPath('column.config.auto_assign_creator', true)
        ->assertJsonPath("filled_values.{$item->id}", [(string) $author->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['name' => 'New', 'group_id' => $group->id])
        ->assertJsonPath("item.values.{$column->id}", [(string) $user->id]);
});

test('fill empty cells assigns creators only where a People column is empty', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    [$board, $view, $group] = createPeopleAutoAssignTestBoard();
    $column = createReporterColumn($board, $view, false);
    $empty = $board->items()->create(['group_id' => $group->id, 'name' => 'Empty', 'position' => 0, 'created_by_id' => $author->id]);
    $cleared = $board->items()->create(['group_id' => $group->id, 'name' => 'Cleared', 'position' => 1, 'created_by_id' => $author->id]);
    $cleared->values()->create(['column_id' => $column->id, 'value' => []]);
    $set = $board->items()->create(['group_id' => $group->id, 'name' => 'Set', 'position' => 2, 'created_by_id' => $author->id]);
    $set->values()->create(['column_id' => $column->id, 'value' => [(string) $teammate->id]]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns/{$column->id}/fill-creators")
        ->assertOk()
        ->assertJsonPath("filled_values.{$empty->id}", [(string) $author->id])
        ->assertJsonPath("filled_values.{$cleared->id}", [(string) $author->id])
        ->assertJsonMissingPath("filled_values.{$set->id}");

    $this->assertDatabaseHas('board_item_values', ['item_id' => $set->id, 'column_id' => $column->id, 'value' => json_encode([(string) $teammate->id])]);
});

test('fill empty cells with creators is refused for a column that is not a People column', function () {
    $user = User::factory()->create();
    [$board, $view] = createPeopleAutoAssignTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TEXT]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns/{$column->id}/fill-creators")
        ->assertUnprocessable();
});
