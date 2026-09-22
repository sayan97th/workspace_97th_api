<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemRecurrence;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;

/**
 * Covers every action of the table's per-row "..." menu at the API level:
 * convert to item / subitem, move to item, create below, move to group,
 * duplicate, mark as priority, recurring, archive and delete.
 */
function createRowMenuBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$workspace, $board, $group];
}

function createRowMenuColumn(WorkspaceNavigationItem $board, BoardGroup $group, string $scope, string $type, string $label, ?array $config = null): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $group->board_view_id,
        'scope' => $scope,
        'type' => $type,
        'label' => $label,
        'config' => $config,
    ]);
}

function createRowMenuViewer(Workspace $workspace): User
{
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);

    return $viewer;
}

// ── Convert to subitem / Convert to item / Move to item ────────────────────

test('converting an item into a subitem appends it after the existing subitems of the new parent', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Existing', 'position' => 4]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath('item.parent_id', $parent->id)
        ->assertJsonPath('item.position', 5);

    expect($item->fresh()->parent_id)->toBe($parent->id);
});

test('a converted subitem shows up nested under its new parent in the index and leaves the root list', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])->assertOk();

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.subitem_count', 1)
        ->assertJsonPath('data.0.children.0.id', $item->id);
});

test('promoting a subitem lands it at the end of the chosen table', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Last root', 'position' => 7]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$sub->id}/parent", ['parent_id' => null, 'group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('item.parent_id', null)
        ->assertJsonPath('item.position', 8);

    $index = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk();
    expect(collect($index->json('data'))->pluck('id'))->toContain($sub->id);
    expect(collect($index->json('data.0.children'))->pluck('id')->all())->not->toContain($sub->id);
});

test('moving a subitem to another parent keeps its values because both parents use the same subitem columns', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $notes = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $first->id, 'name' => 'Sub', 'position' => 0]);
    $sub->values()->create(['column_id' => $notes->id, 'value' => 'stays']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$sub->id}/parent", ['parent_id' => $second->id])
        ->assertOk()
        ->assertJsonPath('item.parent_id', $second->id)
        ->assertJsonPath("item.values.{$notes->id}", 'stays');
});

test('promoting a subitem carries its values back to the matching item columns and assigns an item auto number', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $sub_notes = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $item_notes = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $item_number = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_AUTO_NUMBER, 'ID');
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);
    $sub->values()->create(['column_id' => $sub_notes->id, 'value' => 'moved back']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$sub->id}/parent", ['parent_id' => null, 'group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath("item.values.{$item_notes->id}", 'moved back')
        ->assertJsonPath("item.values.{$item_number->id}", 1);
});

test('a status value is translated to the option with the same label in the target column', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item_status = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_STATUS, 'Status', [
        'options' => [['id' => 'item_done', 'label' => 'Done', 'color' => '#00c875'], ['id' => 'item_stuck', 'label' => 'Stuck', 'color' => '#e2445c']],
    ]);
    $sub_status = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_STATUS, 'Status', [
        'options' => [['id' => 'sub_done', 'label' => 'done', 'color' => '#00c875']],
    ]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);
    $item->values()->create(['column_id' => $item_status->id, 'value' => 'item_done']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath("item.values.{$sub_status->id}", 'sub_done');
});

test('a status option the target column does not have is dropped instead of carried over as a dangling id', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item_status = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_STATUS, 'Status', [
        'options' => [['id' => 'item_stuck', 'label' => 'Stuck', 'color' => '#e2445c']],
    ]);
    $sub_status = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_STATUS, 'Status', [
        'options' => [['id' => 'sub_done', 'label' => 'Done', 'color' => '#00c875']],
    ]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);
    $item->values()->create(['column_id' => $item_status->id, 'value' => 'item_stuck']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])->assertOk();

    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $sub_status->id]);
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $item_status->id]);
});

test('a dropdown value keeps only the options that exist in the target column', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item_dropdown = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_DROPDOWN, 'Project', [
        'options' => [['id' => 'i1', 'label' => 'Alpha', 'color' => '#111111'], ['id' => 'i2', 'label' => 'Beta', 'color' => '#222222']],
    ]);
    $sub_dropdown = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_DROPDOWN, 'Project', [
        'options' => [['id' => 's2', 'label' => 'Beta', 'color' => '#222222']],
    ]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);
    $item->values()->create(['column_id' => $item_dropdown->id, 'value' => ['i1', 'i2']]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath("item.values.{$sub_dropdown->id}", ['s2']);
});

test('a column with the same label but a different type does not receive the value', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item_column = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_TEXT, 'Budget');
    $sub_column = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_NUMBER, 'Budget');
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);
    $item->values()->create(['column_id' => $item_column->id, 'value' => 'lots']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])->assertOk();

    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $sub_column->id]);
    $this->assertDatabaseMissing('board_item_values', ['item_id' => $item->id, 'column_id' => $item_column->id]);
});

test('two source columns with the same label never write to the same target column', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $first = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $second = createRowMenuColumn($board, $group, BoardColumn::SCOPE_ITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $target = createRowMenuColumn($board, $group, BoardColumn::SCOPE_SUBITEM, BoardColumn::TYPE_TEXT, 'Notes');
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);
    $item->values()->create(['column_id' => $first->id, 'value' => 'one']);
    $item->values()->create(['column_id' => $second->id, 'value' => 'two']);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])->assertOk();

    expect($item->values()->where('column_id', $target->id)->count())->toBe(1);
    expect($item->values()->count())->toBe(1);
});

test('a converted row keeps its own name, description and priority flag', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'description' => 'Details', 'position' => 1, 'is_priority' => true]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath('item.name', 'Task')
        ->assertJsonPath('item.description', 'Details')
        ->assertJsonPath('item.is_priority', true);
});

test('converting is rejected when the new parent is itself a subitem', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $root = $board->items()->create(['group_id' => $group->id, 'name' => 'Root', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $root->id, 'name' => 'Sub', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $sub->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');
    expect($item->fresh()->parent_id)->toBeNull();
});

test('converting is rejected when the new parent belongs to another board', function () {
    [, $board, $group] = createRowMenuBoard();
    [, $other_board, $other_group] = createRowMenuBoard();
    $user = User::factory()->create();
    $foreign_parent = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $foreign_parent->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');
});

test('promoting a subitem into a table of another board is rejected', function () {
    [, $board, $group] = createRowMenuBoard();
    [, , $other_group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$sub->id}/parent", ['parent_id' => null, 'group_id' => $other_group->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('group_id');
});

test('converting an item that belongs to another board answers 404', function () {
    [, $board, $group] = createRowMenuBoard();
    [, $other_board, $other_group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $foreign = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$foreign->id}/parent", ['parent_id' => $parent->id])
        ->assertNotFound();
});

test('a workspace viewer cannot convert a row', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);

    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])
        ->assertForbidden();
    expect($item->fresh()->parent_id)->toBeNull();
});

test('a guest cannot convert a row', function () {
    [, $board, $group] = createRowMenuBoard();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 1]);

    $this->patchJson("/api/boards/{$board->id}/items/{$item->id}/parent", ['parent_id' => $parent->id])->assertUnauthorized();
});

// ── Create new item / subitem below ─────────────────────────────────────────

test('an item created below ignores a group_id sent by the client and uses the group of the item above', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $above = $board->items()->create(['group_id' => $group->id, 'name' => 'Above', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Below', 'group_id' => $other_group->id, 'after_item_id' => $above->id])
        ->assertCreated()
        ->assertJsonPath('item.group_id', $group->id);
});

test('creating below only shifts the siblings in the same table', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $other_group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $above = $board->items()->create(['group_id' => $group->id, 'name' => 'Above', 'position' => 0]);
    $same_table = $board->items()->create(['group_id' => $group->id, 'name' => 'Same table', 'position' => 1]);
    $other_table = $board->items()->create(['group_id' => $other_group->id, 'name' => 'Other table', 'position' => 1]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Below', 'after_item_id' => $above->id])->assertCreated();

    expect($same_table->fresh()->position)->toBe(2);
    expect($other_table->fresh()->position)->toBe(1);
});

test('creating below the last row appends it without moving anything', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $last = $board->items()->create(['group_id' => $group->id, 'name' => 'Last', 'position' => 1]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Appended', 'after_item_id' => $last->id])
        ->assertCreated()
        ->assertJsonPath('item.position', 2);
    expect($first->fresh()->position)->toBe(0);
    expect($last->fresh()->position)->toBe(1);
});

test('rows created below appear in the index in the right order', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Third', 'position' => 1]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Second', 'after_item_id' => $first->id])->assertCreated();

    $names = collect($this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->json('data'))->pluck('name')->all();
    expect($names)->toBe(['First', 'Second', 'Third']);
});

test('creating below a deleted item is rejected', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $gone = $board->items()->create(['group_id' => $group->id, 'name' => 'Gone', 'position' => 0]);
    $gone->delete();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Nope', 'after_item_id' => $gone->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('after_item_id');
});

test('creating an item still requires a group when neither parent_id nor after_item_id is given', function () {
    [, $board] = createRowMenuBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Orphan'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('group_id');
});

test('a workspace viewer cannot create a row below another one', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $above = $board->items()->create(['group_id' => $group->id, 'name' => 'Above', 'position' => 0]);

    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$board->id}/items", ['name' => 'Below', 'after_item_id' => $above->id])
        ->assertForbidden();
    expect(BoardItem::count())->toBe(1);
});

// ── Move to group ───────────────────────────────────────────────────────────

test('moving an item to another table puts it at the end and moves its subitems along', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $target = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $board->items()->create(['group_id' => $target->id, 'name' => 'Already there', 'position' => 3]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $item->id, 'name' => 'Sub', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/move", ['item_ids' => [$item->id], 'group_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('items.0.group_id', $target->id)
        ->assertJsonPath('items.0.position', 4);

    expect($sub->fresh()->group_id)->toBe($target->id);
});

test('a workspace viewer cannot move a row to another table', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $target = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/items/move", ['item_ids' => [$item->id], 'group_id' => $target->id])
        ->assertForbidden();
    expect($item->fresh()->group_id)->toBe($group->id);
});

// ── Duplicate ───────────────────────────────────────────────────────────────

test('a duplicated item is not recurring even when the original is', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Weekly report', 'position' => 0]);
    BoardItemRecurrence::create(['board_item_id' => $item->id, 'frequency' => 'weekly', 'interval_count' => 1, 'next_run_date' => now()->addWeek()->toDateString(), 'is_enabled' => true]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", ['item_ids' => [$item->id]])->assertCreated();

    expect(BoardItemRecurrence::count())->toBe(1);
});

test('duplicating a subitem keeps its priority flag and its parent', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0, 'is_priority' => true]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", ['item_ids' => [$sub->id]])
        ->assertCreated()
        ->assertJsonPath('items.0.parent_id', $parent->id)
        ->assertJsonPath('items.0.is_priority', true)
        ->assertJsonPath('items.0.name', 'Sub (copy)');
});

test('duplicating an item without its subitems leaves the copy with no children', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'parent_id' => $item->id, 'name' => 'Sub', 'position' => 0]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", ['item_ids' => [$item->id], 'with_subitems' => false])
        ->assertCreated()
        ->assertJsonCount(0, 'items.0.children');
    expect(BoardItem::where('name', 'Sub')->count())->toBe(1);
});

test('a workspace viewer cannot duplicate a row', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$board->id}/items/duplicate", ['item_ids' => [$item->id]])->assertForbidden();
    expect(BoardItem::count())->toBe(1);
});

// ── Mark as priority ────────────────────────────────────────────────────────

test('the priority flag survives a reload of the index', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}", ['is_priority' => true])->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonPath('data.0.is_priority', true);
});

test('the priority flag must be a boolean', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}", ['is_priority' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_priority');
});

test('a workspace viewer cannot change the priority of a row', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}", ['is_priority' => true])->assertForbidden();
    expect($item->fresh()->is_priority)->toBeFalse();
});

// ── Recurring ───────────────────────────────────────────────────────────────

test('setting a weekly recurrence schedules the next run one interval from today', function () {
    Carbon::setTestNow('2026-03-10');
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'weekly', 'interval_count' => 2])
        ->assertOk()
        ->assertJsonPath('recurrence.frequency', 'weekly')
        ->assertJsonPath('recurrence.interval_count', 2);

    $recurrence = BoardItemRecurrence::where('board_item_id', $item->id)->firstOrFail();
    expect($recurrence->next_run_date->toDateString())->toBe('2026-03-24');
    expect($recurrence->is_enabled)->toBeTrue();
    Carbon::setTestNow();
});

test('a monthly recurrence does not overflow past the end of a shorter month', function () {
    Carbon::setTestNow('2026-01-31');
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'monthly', 'interval_count' => 1])->assertOk();

    expect(BoardItemRecurrence::where('board_item_id', $item->id)->firstOrFail()->next_run_date->toDateString())->toBe('2026-02-28');
    Carbon::setTestNow();
});

test('setting the recurrence again updates it instead of creating a second one', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'daily', 'interval_count' => 1])->assertOk();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'monthly', 'interval_count' => 3])->assertOk();

    expect(BoardItemRecurrence::where('board_item_id', $item->id)->count())->toBe(1);
    expect(BoardItemRecurrence::where('board_item_id', $item->id)->first()->frequency)->toBe('monthly');
});

test('the index reports the recurrence of an item and null for one without it', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $recurring = $board->items()->create(['group_id' => $group->id, 'name' => 'Recurring', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Plain', 'position' => 1]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$recurring->id}/recurrence", ['frequency' => 'daily', 'interval_count' => 5])->assertOk();

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk();

    $response->assertJsonPath('data.0.recurrence', ['frequency' => 'daily', 'interval_count' => 5])
        ->assertJsonPath('data.1.recurrence', null);
});

test('stopping a recurrence removes it', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'daily', 'interval_count' => 1])->assertOk();

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}/recurrence")->assertOk();

    expect(BoardItemRecurrence::where('board_item_id', $item->id)->exists())->toBeFalse();
    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertJsonPath('data.0.recurrence', null);
});

test('the recurrence needs a known frequency and an interval between 1 and 365', function (array $payload, string $field) {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown frequency' => [['frequency' => 'yearly', 'interval_count' => 1], 'frequency'],
    'missing frequency' => [['interval_count' => 1], 'frequency'],
    'zero interval' => [['frequency' => 'daily', 'interval_count' => 0], 'interval_count'],
    'interval above the maximum' => [['frequency' => 'daily', 'interval_count' => 366], 'interval_count'],
    'missing interval' => [['frequency' => 'daily'], 'interval_count'],
]);

test('a workspace viewer cannot set or stop a recurrence', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Report', 'position' => 0]);

    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/recurrence", ['frequency' => 'daily', 'interval_count' => 1])->assertForbidden();
    $this->actingAs($viewer, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}/recurrence")->assertForbidden();
});

test('a recurrence cannot be set on an item of another board', function () {
    [, $board] = createRowMenuBoard();
    [, $other_board, $other_group] = createRowMenuBoard();
    $user = User::factory()->create();
    $foreign = $other_board->items()->create(['group_id' => $other_group->id, 'name' => 'Foreign', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$foreign->id}/recurrence", ['frequency' => 'daily', 'interval_count' => 1])->assertNotFound();
});

// ── Archive ─────────────────────────────────────────────────────────────────

test('archiving a subitem hides it but leaves its parent and siblings visible', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $keep = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Keep', 'position' => 0]);
    $archive = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Archive', 'position' => 1]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$archive->id]])->assertOk();

    expect($archive->fresh()->is_archived)->toBeTrue();
    expect($archive->fresh()->deleted_at)->toBeNull();
    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonCount(1, 'data.0.children')
        ->assertJsonPath('data.0.children.0.id', $keep->id);
});

test('archiving a root item hides it from the index and lists it in the archive panel', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Old task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$item->id]])->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertJsonCount(0, 'data');
    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/trash")
        ->assertOk()
        ->assertJsonPath('archived.0.id', (string) $item->id)
        ->assertJsonCount(0, 'trashed');
});

test('restoring an archived subitem brings it back under its parent', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$sub->id]])->assertOk();

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/{$sub->id}/restore")->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertJsonPath('data.0.subitem_count', 1)
        ->assertJsonPath('data.0.children.0.id', $sub->id);
});

test('archiving a root item keeps its subitems attached for when it is restored', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$parent->id]])->assertOk();

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/{$parent->id}/restore")->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonCount(1, 'data.0.children');
});

test('an archived item is not counted in its table item count', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $board->items()->create(['group_id' => $group->id, 'name' => 'Live', 'position' => 0]);
    $archived = $board->items()->create(['group_id' => $group->id, 'name' => 'Archived', 'position' => 1]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$archived->id]])->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/groups?view_id={$group->board_view_id}")
        ->assertOk()
        ->assertJsonPath('data.0.item_count', 1);
});

test('a workspace viewer cannot archive a row', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$item->id]])->assertForbidden();
    expect($item->fresh()->is_archived)->toBeFalse();
});

// ── Delete ──────────────────────────────────────────────────────────────────

test('deleting a subitem soft-deletes only that row', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Sub', 'position' => 0]);

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items/{$sub->id}")->assertOk();

    expect(BoardItem::find($sub->id))->toBeNull();
    expect(BoardItem::withTrashed()->find($sub->id)->deleted_at)->not->toBeNull();
    expect(BoardItem::find($parent->id))->not->toBeNull();
});

test('a deleted row shows up in the trash panel and can be restored', function () {
    [, $board, $group] = createRowMenuBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}")->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/trash")->assertJsonPath('trashed.0.id', (string) $item->id);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/{$item->id}/restore")->assertOk();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items")->assertJsonCount(1, 'data');
});

test('a workspace viewer cannot delete a row', function () {
    [$workspace, $board, $group] = createRowMenuBoard();
    $viewer = createRowMenuViewer($workspace);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($viewer, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}")->assertForbidden();
    expect(BoardItem::find($item->id))->not->toBeNull();
});
