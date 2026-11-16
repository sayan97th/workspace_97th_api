<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardDuplicationService;

/**
 * A board with a Main table holding one column, one group and one item.
 *
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardColumn, 3: BoardGroup, 4: BoardItem}
 */
function createFilterViewTestBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $main_view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true, 'label' => 'Main table']);
    $column = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $main_view->id,
        'key' => 'status',
        'type' => BoardColumn::TYPE_STATUS,
        'position' => 0,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $main_view->id, 'position' => 0]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Task one']);

    return [$board, $main_view, $column, $group, $item];
}

test('a new table view is a filter view of the main table', function () {
    $user = User::factory()->create();
    [$board, $main_view] = createFilterViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'Rachel'])
        ->assertCreated()
        ->assertJsonPath('view.view_type', 'table')
        ->assertJsonPath('view.source_view_id', $main_view->id);
});

test('gantt and calendar views show the main table while kanban keeps its own items', function (string $view_type, bool $is_filter_view) {
    $user = User::factory()->create();
    [$board, $main_view] = createFilterViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'View', 'view_type' => $view_type])
        ->assertCreated()
        ->assertJsonPath('view.source_view_id', $is_filter_view ? $main_view->id : null);
})->with([
    ['gantt', true],
    ['calendar', true],
    ['kanban', false],
    ['doc', false],
    ['chart', false],
]);

test('a view can still be created blank on purpose', function () {
    $user = User::factory()->create();
    [$board] = createFilterViewTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'Scratch', 'source_view_id' => null])
        ->assertCreated()
        ->assertJsonPath('view.source_view_id', null);
});

test('a filter view of a filter view points at the tab that stores the data', function () {
    $user = User::factory()->create();
    [$board, $main_view] = createFilterViewTestBoard();
    $filter_view = BoardView::factory()->create(['board_id' => $board->id, 'source_view_id' => $main_view->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views", ['label' => 'Nested', 'source_view_id' => $filter_view->id])
        ->assertCreated()
        ->assertJsonPath('view.source_view_id', $main_view->id);
});

test('a filter view reads the same columns, groups and items as the main table', function () {
    $user = User::factory()->create();
    [$board, $main_view, $column, $group, $item] = createFilterViewTestBoard();
    $filter_view = BoardView::factory()->create(['board_id' => $board->id, 'source_view_id' => $main_view->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/columns?view_id={$filter_view->id}")
        ->assertOk()
        ->assertJsonFragment(['id' => $column->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/groups?view_id={$filter_view->id}")
        ->assertOk()
        ->assertJsonFragment(['id' => $group->id]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items?view_id={$filter_view->id}")
        ->assertOk()
        ->assertJsonFragment(['id' => $item->id, 'name' => 'Task one']);
});

test('content added from a filter view lands on the main table', function () {
    $user = User::factory()->create();
    [$board, $main_view] = createFilterViewTestBoard();
    $filter_view = BoardView::factory()->create(['board_id' => $board->id, 'source_view_id' => $main_view->id]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/groups", ['view_id' => $filter_view->id, 'name' => 'From the filter view'])
        ->assertCreated();

    expect(BoardGroup::where('name', 'From the filter view')->value('board_view_id'))->toBe($main_view->id)
        ->and(BoardGroup::where('board_view_id', $filter_view->id)->count())->toBe(0);
});

test('saving filters on a filter view leaves the main table settings alone', function () {
    $user = User::factory()->create();
    [$board, $main_view, $column] = createFilterViewTestBoard();
    $filter_view = BoardView::factory()->create(['board_id' => $board->id, 'source_view_id' => $main_view->id]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$filter_view->id}", ['hidden_column_ids' => [(string) $column->id]])
        ->assertOk()
        ->assertJsonPath('view.hidden_column_ids', [(string) $column->id]);

    expect($main_view->fresh()->hidden_column_ids)->toBeNull();
});

test('duplicating the main table creates a filter view with the same saved state', function () {
    $user = User::factory()->create();
    [$board, $main_view, $column] = createFilterViewTestBoard();
    $main_view->update(['hidden_column_ids' => [(string) $column->id]]);
    $column_count = BoardColumn::count();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$main_view->id}/duplicate", ['label' => 'Main table (filtered)'])
        ->assertCreated()
        ->assertJsonPath('view.source_view_id', $main_view->id)
        ->assertJsonPath('view.hidden_column_ids', [(string) $column->id]);

    expect(BoardColumn::count())->toBe($column_count);
});

test('deleting a view whose items other views show is refused', function () {
    $user = User::factory()->create();
    [$board] = createFilterViewTestBoard();
    $own_data_view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    BoardView::factory()->create(['board_id' => $board->id, 'source_view_id' => $own_data_view->id]);

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$board->id}/views/{$own_data_view->id}")
        ->assertStatus(422);

    expect(BoardView::find($own_data_view->id))->not->toBeNull();
});

test('a whole board duplicate links filter views to the copied main table', function () {
    [$board, $main_view, $column] = createFilterViewTestBoard();
    BoardView::factory()->create([
        'board_id' => $board->id,
        'source_view_id' => $main_view->id,
        'label' => 'Rachel',
        'hidden_column_ids' => [(string) $column->id],
    ]);
    $target_board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $board->workspace_id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);

    app(BoardDuplicationService::class)->duplicateAllViews($board, $target_board);

    $copied_main = BoardView::where('board_id', $target_board->id)->where('is_primary', true)->firstOrFail();
    $copied_filter = BoardView::where('board_id', $target_board->id)->where('label', 'Rachel')->firstOrFail();
    $copied_column = BoardColumn::where('board_view_id', $copied_main->id)->firstOrFail();

    expect($copied_filter->source_view_id)->toBe($copied_main->id)
        ->and($copied_filter->hidden_column_ids)->toBe([(string) $copied_column->id])
        ->and(BoardColumn::where('board_view_id', $copied_filter->id)->count())->toBe(0);
});
