<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Covers the table's selection action bar (monday.com "Batch Actions") when
 * the selection is made of subitems: duplicate, archive, delete, convert to
 * items, move to another item, and the Undo that restores archived or
 * deleted rows.
 */
function createSubitemBatchBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Parent', 'position' => 0]);
    $other_parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Other parent', 'position' => 1]);
    $first_sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'First sub', 'position' => 0]);
    $second_sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $other_parent->id, 'name' => 'Second sub', 'position' => 0]);

    return [$workspace, $board, $group, $parent, $other_parent, $first_sub, $second_sub];
}

test('subitems from different parents can be deleted together', function () {
    [, $board, , $parent, $other_parent, $first_sub, $second_sub] = createSubitemBatchBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->deleteJson("/api/boards/{$board->id}/items", ['item_ids' => [$first_sub->id, $second_sub->id]])
        ->assertOk();

    expect(BoardItem::find($first_sub->id))->toBeNull()
        ->and(BoardItem::find($second_sub->id))->toBeNull()
        ->and(BoardItem::find($parent->id))->not->toBeNull()
        ->and(BoardItem::find($other_parent->id))->not->toBeNull();
});

test('duplicating subitems keeps each copy under its own parent', function () {
    [, $board, , $parent, $other_parent, $first_sub, $second_sub] = createSubitemBatchBoard();

    $response = $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/items/duplicate", ['item_ids' => [$first_sub->id, $second_sub->id]])
        ->assertCreated();

    expect(collect($response->json('items'))->pluck('parent_id')->sort()->values()->all())
        ->toBe(collect([$parent->id, $other_parent->id])->sort()->values()->all());
});

test('converting subitems to items keeps each one in its parent table', function () {
    [, $board, $group, , , $first_sub, $second_sub] = createSubitemBatchBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/parent", ['item_ids' => [$first_sub->id, $second_sub->id], 'parent_id' => null])
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect($first_sub->fresh()->parent_id)->toBeNull()
        ->and($first_sub->fresh()->group_id)->toBe($group->id)
        ->and($second_sub->fresh()->parent_id)->toBeNull();
});

test('subitems can be moved under another item together', function () {
    [, $board, , , $other_parent, $first_sub] = createSubitemBatchBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/parent", ['item_ids' => [$first_sub->id], 'parent_id' => $other_parent->id])
        ->assertOk()
        ->assertJsonPath('items.0.parent_id', $other_parent->id)
        ->assertJsonPath('items.0.position', 1);
});

test('an item with its own subitems cannot be converted into a subitem in bulk', function () {
    [, $board, , $parent, $other_parent] = createSubitemBatchBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/parent", ['item_ids' => [$parent->id], 'parent_id' => $other_parent->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');
});

test('a row cannot be placed under itself', function () {
    [, $board, , , $other_parent] = createSubitemBatchBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/parent", ['item_ids' => [$other_parent->id], 'parent_id' => $other_parent->id])
        ->assertUnprocessable();
});

test('restore brings back deleted items with the subitems deleted alongside them', function () {
    [, $board, , $parent, , $first_sub] = createSubitemBatchBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items", ['item_ids' => [$parent->id]])->assertOk();
    expect(BoardItem::find($first_sub->id))->toBeNull();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/items/restore", ['item_ids' => [$parent->id]])
        ->assertOk();

    expect(BoardItem::find($parent->id))->not->toBeNull()
        ->and(BoardItem::find($first_sub->id))->not->toBeNull();
});

test('restore unarchives archived subitems', function () {
    [, $board, , , , $first_sub] = createSubitemBatchBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$first_sub->id]])->assertOk();
    expect($first_sub->fresh()->is_archived)->toBeTrue();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/items/restore", ['item_ids' => [$first_sub->id]])
        ->assertOk();

    expect($first_sub->fresh()->is_archived)->toBeFalse();
});

test('viewers cannot run batch parent changes', function () {
    [$workspace, $board, , , $other_parent, $first_sub] = createSubitemBatchBoard();
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);

    $this->actingAs($viewer, 'api')
        ->patchJson("/api/boards/{$board->id}/items/parent", ['item_ids' => [$first_sub->id], 'parent_id' => $other_parent->id])
        ->assertForbidden();
});
