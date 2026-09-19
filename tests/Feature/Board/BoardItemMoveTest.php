<?php

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{board: WorkspaceNavigationItem, view: BoardView, group: BoardGroup}
 */
function createMoveTestBoard(Workspace $workspace, string $label = 'Board'): array
{
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => $label,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return ['board' => $board, 'view' => $view, 'group' => $group];
}

function createMoveTestColumn(array $board_parts, string $label, string $type, ?array $config = null): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board_parts['board']->id,
        'board_view_id' => $board_parts['view']->id,
        'scope' => BoardColumn::SCOPE_ITEM,
        'label' => $label,
        'key' => strtolower($label),
        'type' => $type,
        'config' => $config,
    ]);
}

test('move targets list the other editable boards of the workspace with their tables', function () {
    $workspace = Workspace::factory()->create();
    $source = createMoveTestBoard($workspace, 'Source');
    $target = createMoveTestBoard($workspace, 'Target');
    createMoveTestBoard(Workspace::factory()->create(), 'Other workspace board');
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$source['board']->id}/move-targets");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target['board']->id)
        ->assertJsonPath('data.0.label', 'Target')
        ->assertJsonPath('data.0.groups.0.id', $target['group']->id);
});

test('an item moves to a table of another board carrying matching column values', function () {
    $workspace = Workspace::factory()->create();
    $source = createMoveTestBoard($workspace, 'Source');
    $target = createMoveTestBoard($workspace, 'Target');
    $user = User::factory()->create();

    $source_notes = createMoveTestColumn($source, 'Notes', BoardColumn::TYPE_TEXT);
    $source_owner = createMoveTestColumn($source, 'Owner', BoardColumn::TYPE_TEXT);
    $target_notes = createMoveTestColumn($target, 'notes', BoardColumn::TYPE_TEXT);
    $source_status = createMoveTestColumn($source, 'Status', BoardColumn::TYPE_STATUS, [
        'options' => [['id' => 'src-done', 'label' => 'Done', 'color' => '#00c875', 'is_active' => true]],
    ]);
    $target_status = createMoveTestColumn($target, 'Status', BoardColumn::TYPE_STATUS, [
        'options' => [['id' => 'tgt-done', 'label' => 'Done', 'color' => '#00c875', 'is_active' => true]],
    ]);

    $item = $source['board']->items()->create(['group_id' => $source['group']->id, 'name' => 'Launch', 'position' => 0]);
    $child = $source['board']->items()->create([
        'group_id' => $source['group']->id, 'parent_id' => $item->id, 'name' => 'Subtask', 'position' => 0,
    ]);
    $item->values()->create(['column_id' => $source_notes->id, 'value' => 'hello']);
    $item->values()->create(['column_id' => $source_owner->id, 'value' => 'no match on target']);
    $item->values()->create(['column_id' => $source_status->id, 'value' => 'src-done']);
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $user->id, 'body' => 'An update']);

    $response = $this->actingAs($user, 'api')->patchJson("/api/boards/{$source['board']->id}/items/{$item->id}/board", [
        'target_board_id' => $target['board']->id,
        'target_group_id' => $target['group']->id,
    ]);

    $response->assertOk()->assertJsonPath('target_board.id', $target['board']->id);

    expect($item->fresh())
        ->board_id->toBe($target['board']->id)
        ->group_id->toBe($target['group']->id)
        ->and($child->fresh())
        ->board_id->toBe($target['board']->id)
        ->group_id->toBe($target['group']->id)
        ->and($comment->fresh()->item_id)->toBe($item->id);

    $values = $item->fresh()->values->keyBy('column_id');
    expect($values)->toHaveCount(2)
        ->and($values->get($target_notes->id)->value)->toBe('hello')
        ->and($values->get($target_status->id)->value)->toBe('tgt-done');

    expect(BoardActivityLog::where('action', BoardActivityLog::ACTION_ITEM_MOVED)->count())->toBe(2);
});

test('moving an item to another board rejects a board from a different workspace', function () {
    $source = createMoveTestBoard(Workspace::factory()->create());
    $foreign = createMoveTestBoard(Workspace::factory()->create());
    $user = User::factory()->create();
    $item = $source['board']->items()->create(['group_id' => $source['group']->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$source['board']->id}/items/{$item->id}/board", [
        'target_board_id' => $foreign['board']->id,
        'target_group_id' => $foreign['group']->id,
    ])->assertNotFound();

    expect($item->fresh()->board_id)->toBe($source['board']->id);
});

test('only top-level items can be moved to another board', function () {
    $workspace = Workspace::factory()->create();
    $source = createMoveTestBoard($workspace);
    $target = createMoveTestBoard($workspace);
    $user = User::factory()->create();
    $parent = $source['board']->items()->create(['group_id' => $source['group']->id, 'name' => 'Parent', 'position' => 0]);
    $child = $source['board']->items()->create([
        'group_id' => $source['group']->id, 'parent_id' => $parent->id, 'name' => 'Child', 'position' => 0,
    ]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$source['board']->id}/items/{$child->id}/board", [
        'target_board_id' => $target['board']->id,
        'target_group_id' => $target['group']->id,
    ])->assertStatus(422);
});

test('moving an item into its own board is rejected', function () {
    $source = createMoveTestBoard(Workspace::factory()->create());
    $user = User::factory()->create();
    $item = $source['board']->items()->create(['group_id' => $source['group']->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$source['board']->id}/items/{$item->id}/board", [
        'target_board_id' => $source['board']->id,
        'target_group_id' => $source['group']->id,
    ])->assertStatus(422);
});

test('a target group must belong to the target board', function () {
    $workspace = Workspace::factory()->create();
    $source = createMoveTestBoard($workspace);
    $target = createMoveTestBoard($workspace);
    $user = User::factory()->create();
    $item = $source['board']->items()->create(['group_id' => $source['group']->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$source['board']->id}/items/{$item->id}/board", [
        'target_board_id' => $target['board']->id,
        'target_group_id' => $source['group']->id,
    ])->assertNotFound();

    expect(BoardItem::find($item->id)->board_id)->toBe($source['board']->id);
});
