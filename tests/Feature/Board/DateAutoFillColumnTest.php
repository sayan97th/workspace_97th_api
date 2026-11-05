<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup}
 */
function createDateAutoFillTestBoard(): array
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

function createAutoFillDateColumn(WorkspaceNavigationItem $board, BoardView $view, array $auto_fill): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_DATE,
        'config' => ['auto_fill' => $auto_fill],
    ]);
}

afterEach(fn () => Carbon::setTestNow());

test('a new item gets its creation date and time in an auto fill Date column', function () {
    Carbon::setTestNow('2026-11-05 14:30:00');
    $user = User::factory()->create(['timezone' => 'UTC']);
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = createAutoFillDateColumn($board, $view, ['enabled' => true, 'include_time' => true]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Task', 'group_id' => $group->id]);

    $response->assertCreated()->assertJsonPath("item.values.{$column->id}", '2026-11-05T14:30');
});

test('the auto filled date uses the creator time zone and can leave the time out', function () {
    Carbon::setTestNow('2026-11-06 03:00:00');
    $user = User::factory()->create(['timezone' => 'America/Mexico_City']);
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = createAutoFillDateColumn($board, $view, ['enabled' => true, 'include_time' => false]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Task', 'group_id' => $group->id]);

    // 03:00 UTC on Nov 6 is still the evening of Nov 5 in Mexico City.
    $response->assertCreated()->assertJsonPath("item.values.{$column->id}", '2026-11-05');
});

test('a date sent with the new item wins over the auto fill', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = createAutoFillDateColumn($board, $view, ['enabled' => true, 'include_time' => false]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", [
        'name' => 'Task',
        'group_id' => $group->id,
        'values' => [(string) $column->id => '2026-01-15'],
    ]);

    $response->assertCreated()->assertJsonPath("item.values.{$column->id}", '2026-01-15');
});

test('a Date column without auto fill stays empty on new items', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Task', 'group_id' => $group->id]);

    $response->assertCreated();
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $response->json('item.id'), 'column_id' => $column->id]);
});

test('turning a Creation log column into a Date column keeps the dates and keeps auto filling', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_CREATION_LOG]);
    $empty = $board->items()->create(['group_id' => $group->id, 'name' => 'Empty', 'position' => 0, 'created_by_id' => $user->id]);
    $empty->forceFill(['created_at' => '2026-03-10 09:15:00'])->save();
    $kept = $board->items()->create(['group_id' => $group->id, 'name' => 'Kept', 'position' => 1]);
    // A date stored while the column was still a Date column comes back as it was.
    $kept->values()->create(['column_id' => $column->id, 'value' => '2025-12-01']);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['type' => BoardColumn::TYPE_DATE]);

    $response->assertOk()
        ->assertJsonPath('column.type', BoardColumn::TYPE_DATE)
        ->assertJsonPath('column.config.auto_fill.enabled', true)
        ->assertJsonPath("filled_values.{$empty->id}", '2026-03-10T09:15')
        ->assertJsonMissingPath("filled_values.{$kept->id}");
    $this->assertDatabaseHas('board_item_values', ['item_id' => $kept->id, 'column_id' => $column->id, 'value' => json_encode('2025-12-01')]);

    $new_item = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'New', 'group_id' => $group->id]);
    expect($new_item->json("item.values.{$column->id}"))->toBeString()->not->toBeEmpty();
});

test('turning a Date column into a Creation log keeps its stored dates for a later switch back', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $item->values()->create(['column_id' => $column->id, 'value' => '2026-02-02']);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['type' => BoardColumn::TYPE_CREATION_LOG])
        ->assertOk()
        ->assertJsonPath('column.type', BoardColumn::TYPE_CREATION_LOG);

    $this->assertDatabaseHas('board_item_values', ['item_id' => $item->id, 'column_id' => $column->id, 'value' => json_encode('2026-02-02')]);
});

test('fill empty cells writes creation dates only where a Date column is empty', function () {
    $user = User::factory()->create();
    [$board, $view, $group] = createDateAutoFillTestBoard();
    $column = createAutoFillDateColumn($board, $view, ['enabled' => false, 'include_time' => false]);
    $empty = $board->items()->create(['group_id' => $group->id, 'name' => 'Empty', 'position' => 0]);
    $empty->forceFill(['created_at' => '2026-04-01 10:00:00'])->save();
    $set = $board->items()->create(['group_id' => $group->id, 'name' => 'Set', 'position' => 1]);
    $set->values()->create(['column_id' => $column->id, 'value' => '2026-05-05']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns/{$column->id}/fill-dates")
        ->assertOk()
        ->assertJsonPath("filled_values.{$empty->id}", '2026-04-01')
        ->assertJsonMissingPath("filled_values.{$set->id}");

    $this->assertDatabaseHas('board_item_values', ['item_id' => $set->id, 'column_id' => $column->id, 'value' => json_encode('2026-05-05')]);
});

test('fill empty cells is refused for a column that is not a Date column', function () {
    $user = User::factory()->create();
    [$board, $view] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_TEXT]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns/{$column->id}/fill-dates")
        ->assertUnprocessable();
});

test('a date column saves how its cells show dates', function () {
    $user = User::factory()->create();
    [$board, $view] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_CREATION_LOG]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", [
            'config' => ['date_display' => ['format' => 'eu_numeric', 'time_format' => '24', 'show_time' => true]],
        ])
        ->assertOk()
        ->assertJsonPath('column.config.date_display.format', 'eu_numeric')
        ->assertJsonPath('column.config.date_display.time_format', '24');
});

test('an unknown date format is refused', function () {
    $user = User::factory()->create();
    [$board, $view] = createDateAutoFillTestBoard();
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_DATE]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$column->id}", ['config' => ['date_display' => ['format' => 'dd.mm.yy']]])
        ->assertUnprocessable();
});
