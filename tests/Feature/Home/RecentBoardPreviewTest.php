<?php

use App\Models\BoardColumn;
use App\Models\BoardDoc;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\BoardVisit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * The "Recently visited" card previews on the Home page: each card previews
 * the tab the user last had open, shaped for that tab's kind.
 */
function createPreviewBoard(User $user, array $attributes = []): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        ...$attributes,
    ]);
}

test('opening a tab remembers it on the visit', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user);
    BoardView::factory()->create(['board_id' => $board->id]);
    $kanban = BoardView::factory()->create(['board_id' => $board->id, 'view_type' => 'kanban', 'is_primary' => false, 'label' => 'Sprint']);

    $this->actingAs($user, 'api')->putJson("/api/boards/{$board->id}/views/{$kanban->id}/visit")->assertOk();

    expect(BoardVisit::where('user_id', $user->id)->where('board_id', $board->id)->value('board_view_id'))->toBe($kanban->id);
});

test('a tab of another board can not be recorded', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user);
    $other_view = BoardView::factory()->create();

    $this->actingAs($user, 'api')->putJson("/api/boards/{$board->id}/views/{$other_view->id}/visit")->assertNotFound();
});

test('a kanban visit previews its status lanes with real counts', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user);
    BoardView::factory()->create(['board_id' => $board->id]);
    $kanban = BoardView::factory()->create(['board_id' => $board->id, 'view_type' => 'kanban', 'is_primary' => false, 'label' => 'Sprint']);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $kanban->id]);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $kanban->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'todo', 'label' => 'To do', 'color' => '#579bfc'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);
    foreach (['todo', 'todo', 'done'] as $option_id) {
        BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id])
            ->values()->create(['column_id' => $status->id, 'value' => $option_id]);
    }

    BoardVisit::recordView($user->id, $board->id, $kanban->id);

    $this->actingAs($user, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonPath('data.0.preview.kind', 'kanban')
        ->assertJsonPath('data.0.preview.view_label', 'Sprint')
        ->assertJsonPath('data.0.preview.kanban.lanes.0.label', 'To do')
        ->assertJsonPath('data.0.preview.kanban.lanes.0.item_count', 2)
        ->assertJsonPath('data.0.preview.kanban.lanes.1.item_count', 1);
});

test('a deleted tab falls back to the primary tab', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user);
    BoardView::factory()->create(['board_id' => $board->id]);
    $chart = BoardView::factory()->create(['board_id' => $board->id, 'view_type' => 'chart', 'is_primary' => false]);

    BoardVisit::recordView($user->id, $board->id, $chart->id);
    $chart->delete();

    $this->actingAs($user, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonPath('data.0.preview.kind', 'table');
});

test('a table preview carries sample rows with real status colors', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c']]],
    ]);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id])
        ->values()->create(['column_id' => $status->id, 'value' => 'stuck']);

    BoardVisit::record($user->id, $board->id);

    $this->actingAs($user, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonPath('data.0.preview.table.columns.0', 'status')
        ->assertJsonPath("data.0.preview.table.rows.{$group->id}.0.cells.0.color", '#e2445c');
});

test('a doc previews its outline', function () {
    $user = User::factory()->create();
    $board = createPreviewBoard($user, ['view_key' => 'doc']);
    BoardDoc::query()->create(['board_id' => $board->id, 'content' => json_encode(['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Launch plan']]],
        ['type' => 'taskList', 'content' => [
            ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Write brief']]]]],
        ]],
    ]])]);

    BoardVisit::record($user->id, $board->id);

    $this->actingAs($user, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonPath('data.0.preview.kind', 'doc_page')
        ->assertJsonPath('data.0.preview.doc.blocks.0.type', 'heading')
        ->assertJsonPath('data.0.preview.doc.blocks.0.text', 'Launch plan')
        ->assertJsonPath('data.0.preview.doc.blocks.1.type', 'todo')
        ->assertJsonPath('data.0.preview.doc.blocks.1.checked', true);
});
