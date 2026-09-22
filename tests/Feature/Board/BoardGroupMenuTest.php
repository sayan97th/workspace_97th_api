<?php

use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Covers every action of the table's group "..." menu at the API level:
 * change color, mark as priority, rename, move, duplicate, archive and delete.
 */
function createGroupMenuBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);

    return [$workspace, $board];
}

/**
 * Creates `$count` groups on one tab, positioned 0..N-1 in creation order.
 *
 * @return array<int, BoardGroup>
 */
function createOrderedGroups(WorkspaceNavigationItem $board, int $count): array
{
    $groups = [];
    $view_id = null;

    for ($index = 0; $index < $count; $index++) {
        $attributes = ['board_id' => $board->id, 'name' => "Group {$index}", 'position' => $index];
        if ($view_id !== null) {
            $attributes['board_view_id'] = $view_id;
        }
        $groups[] = BoardGroup::factory()->create($attributes);
        $view_id = $groups[0]->board_view_id;
    }

    return $groups;
}

function orderedGroupNames(WorkspaceNavigationItem $board): array
{
    return BoardGroup::where('board_id', $board->id)->where('is_archived', false)->orderBy('position')->pluck('name')->all();
}

// ── Change group color ─────────────────────────────────────────────────────

test('a group color can be changed and is saved', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'accent_color' => '#579bfc']);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['accent_color' => '#e04455'])
        ->assertOk()
        ->assertJsonPath('group.accent_color', '#e04455');

    expect($group->fresh()->accent_color)->toBe('#e04455');
});

test('a group color must be a six digit hex value', function (string $color) {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'accent_color' => '#579bfc']);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['accent_color' => $color])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('accent_color');

    expect($group->fresh()->accent_color)->toBe('#579bfc');
})->with(['red', '#fff', '#12345g', 'e04455']);

test('changing a color keeps the item count of the group', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Task 2', 'position' => 1]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['accent_color' => '#12c46b'])
        ->assertOk()
        ->assertJsonPath('group.item_count', 2);
});

// ── Rename group ───────────────────────────────────────────────────────────

test('a group can be renamed', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Old']);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['name' => 'New name'])
        ->assertOk()
        ->assertJsonPath('group.name', 'New name');

    expect($group->fresh()->name)->toBe('New name');
});

// ── Move group ─────────────────────────────────────────────────────────────

test('moving a group to the top resequences every sibling', function () {
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 4);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$groups[3]->id}/move", ['position' => 0])
        ->assertOk()
        ->assertJsonPath('group.position', 0);

    expect(orderedGroupNames($board))->toBe(['Group 3', 'Group 0', 'Group 1', 'Group 2']);
    expect(BoardGroup::where('board_id', $board->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

test('moving a group down by one swaps it with the next group', function () {
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 3);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$groups[0]->id}/move", ['position' => 1])
        ->assertOk();

    expect(orderedGroupNames($board))->toBe(['Group 1', 'Group 0', 'Group 2']);
});

test('moving a group past the last slot lands it at the bottom', function () {
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 3);

    $response = $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$groups[0]->id}/move", ['position' => 99])
        ->assertOk()
        ->assertJsonPath('group.position', 2);

    expect(orderedGroupNames($board))->toBe(['Group 1', 'Group 2', 'Group 0']);
    expect(collect($response->json('groups'))->pluck('name')->all())->toBe(['Group 1', 'Group 2', 'Group 0']);
});

test('moving a group repairs positions that were not unique', function () {
    [, $board] = createGroupMenuBoard();
    $first = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'A', 'position' => 0]);
    $second = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $first->board_view_id, 'name' => 'B', 'position' => 0]);
    $third = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $first->board_view_id, 'name' => 'C', 'position' => 0]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$third->id}/move", ['position' => 1])
        ->assertOk();

    expect(BoardGroup::where('board_id', $board->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
    expect($third->fresh()->position)->toBe(1);
});

test('moving a group leaves the groups of another tab alone', function () {
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 2);
    $other_tab = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => false]);
    $other = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $other_tab->id, 'position' => 5]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$groups[1]->id}/move", ['position' => 0])
        ->assertOk();

    expect($other->fresh()->position)->toBe(5);
});

test('a group cannot be moved to a negative position', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}/move", ['position' => -1])
        ->assertUnprocessable();
});

// ── Duplicate this group ───────────────────────────────────────────────────

test('a duplicated group lands right below the original and pushes later groups down', function () {
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 3);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/groups/{$groups[0]->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('group.position', 1);

    expect(orderedGroupNames($board))->toBe(['Group 0', 'Group 0 copy', 'Group 1', 'Group 2']);
});

test('a duplicated group keeps the priority flag of its items but skips archived ones', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Starred', 'position' => 0, 'is_priority' => true]);
    $board->items()->create(['group_id' => $group->id, 'name' => 'Hidden', 'position' => 1, 'is_archived' => true]);

    $copy_id = $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/groups/{$group->id}/duplicate", ['with_items' => true])
        ->assertCreated()
        ->json('group.id');

    $this->assertDatabaseHas('board_items', ['group_id' => $copy_id, 'name' => 'Starred', 'is_priority' => true]);
    $this->assertDatabaseMissing('board_items', ['group_id' => $copy_id, 'name' => 'Hidden']);
});

// ── Archive group ──────────────────────────────────────────────────────────

test('archiving a group hides it without deleting it or its items', function () {
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/groups/{$group->id}/archive")
        ->assertOk()
        ->assertJsonPath('group.id', $group->id);

    expect($group->fresh()->is_archived)->toBeTrue();
    expect($group->fresh()->archived_at)->not->toBeNull();
    $this->assertDatabaseHas('board_items', ['id' => $item->id, 'group_id' => $group->id]);
});

test('an archived group is left out of the group list and its items out of the item list', function () {
    $user = User::factory()->create();
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 2);
    $board->items()->create(['group_id' => $groups[0]->id, 'name' => 'Hidden task', 'position' => 0]);
    $board->items()->create(['group_id' => $groups[1]->id, 'name' => 'Visible task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$groups[0]->id}/archive")->assertOk();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/groups")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Group 1');

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible task');
});

test('an archived group shows in the archive panel and can be restored to the end', function () {
    $user = User::factory()->create();
    [, $board] = createGroupMenuBoard();
    $groups = createOrderedGroups($board, 3);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$groups[0]->id}/archive")->assertOk();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/trash")
        ->assertOk()
        ->assertJsonCount(1, 'archived_groups')
        ->assertJsonPath('archived_groups.0.name', 'Group 0');

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/groups/{$groups[0]->id}/restore")->assertOk();

    expect($groups[0]->fresh()->is_archived)->toBeFalse();
    expect(orderedGroupNames($board))->toBe(['Group 1', 'Group 2', 'Group 0']);
});

test('an archived group can be deleted forever from the archive panel', function () {
    $user = User::factory()->create();
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task 1', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}/archive")->assertOk();
    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/trash/groups/{$group->id}")->assertOk();

    $this->assertDatabaseMissing('board_groups', ['id' => $group->id]);
    $this->assertDatabaseMissing('board_items', ['id' => $item->id]);
});

test('a group that is not archived cannot be restored or deleted from the archive panel', function () {
    $user = User::factory()->create();
    [, $board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/groups/{$group->id}/restore")->assertNotFound();
    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/trash/groups/{$group->id}")->assertNotFound();
    $this->assertDatabaseHas('board_groups', ['id' => $group->id]);
});

// ── Board scoping and view-only access ─────────────────────────────────────

test('a group of another board cannot be moved, archived or restored', function () {
    $user = User::factory()->create();
    [, $board] = createGroupMenuBoard();
    [, $other_board] = createGroupMenuBoard();
    $group = BoardGroup::factory()->create(['board_id' => $other_board->id, 'is_archived' => true]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}/move", ['position' => 0])->assertNotFound();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}/archive")->assertNotFound();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/trash/groups/{$group->id}/restore")->assertNotFound();
});

test('a workspace viewer cannot change any group', function () {
    [$workspace, $board] = createGroupMenuBoard();
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Untouched', 'accent_color' => '#579bfc']);

    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$board->id}/groups", ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['accent_color' => '#e04455'])->assertForbidden();
    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}/move", ['position' => 0])->assertForbidden();
    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$board->id}/groups/{$group->id}/duplicate")->assertForbidden();
    $this->actingAs($viewer, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}/archive")->assertForbidden();
    $this->actingAs($viewer, 'api')->deleteJson("/api/boards/{$board->id}/groups/{$group->id}")->assertForbidden();

    expect($group->fresh()->accent_color)->toBe('#579bfc');
    expect(BoardGroup::where('board_id', $board->id)->count())->toBe(1);
});

test('a workspace viewer can still save their own collapsed tables', function () {
    [$workspace, $board] = createGroupMenuBoard();
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    $this->actingAs($viewer, 'api')
        ->putJson("/api/boards/{$board->id}/groups/collapsed-state", ['collapsed_group_ids' => [$group->id]])
        ->assertOk();
});
