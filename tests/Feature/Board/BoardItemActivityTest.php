<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createActivityTestItem(): BoardItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

test('the activity log lists the item changes newest first', function () {
    $item = createActivityTestItem();
    $user = User::factory()->create();

    $first = BoardItemActivity::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'column_id' => 10,
        'column_label' => 'Status',
        'column_type' => 'status',
        'old_display' => 'Working on it',
        'new_display' => 'Done',
    ]);
    $first->forceFill(['created_at' => now()->subHour()])->save();
    BoardItemActivity::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'column_id' => 11,
        'column_label' => 'Owner',
        'column_type' => 'people',
        'old_display' => null,
        'new_display' => $user->full_name,
    ]);

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/activity")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.column_label', 'Owner')
        ->assertJsonPath('data.1.old_display', 'Working on it')
        ->assertJsonPath('data.1.new_display', 'Done')
        ->assertJsonPath('data.1.user.id', $user->id)
        ->assertJsonPath('meta.total', 2);
});

test('the activity log can be narrowed to one column', function () {
    $item = createActivityTestItem();
    $user = User::factory()->create();

    foreach ([10, 11] as $column_id) {
        BoardItemActivity::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'column_id' => $column_id,
            'column_label' => "Column {$column_id}",
            'column_type' => 'text',
            'new_display' => 'Value',
        ]);
    }

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$item->id}/activity?column_id=11")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.column_id', 11);
});

test('the activity log of an item from another board is not found', function () {
    $item = createActivityTestItem();
    $other_item = createActivityTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/{$other_item->id}/activity")
        ->assertNotFound();
});

test('renaming an item and moving it to another group are recorded in its activity log', function () {
    $item = createActivityTestItem();
    $item->group->update(['name' => 'Sprint 1']);
    $next_group = BoardGroup::factory()->create(['board_id' => $item->board_id, 'name' => 'Sprint 2']);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}", ['name' => 'Renamed task', 'group_id' => $next_group->id])
        ->assertOk();

    $this->assertDatabaseHas('board_item_activities', [
        'item_id' => $item->id,
        'column_type' => 'item_name',
        'old_display' => 'Task',
        'new_display' => 'Renamed task',
    ]);
    $this->assertDatabaseHas('board_item_activities', [
        'item_id' => $item->id,
        'column_type' => 'item_group',
        'old_display' => 'Sprint 1',
        'new_display' => 'Sprint 2',
    ]);
});

test('saving the same name records nothing', function () {
    $item = createActivityTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}", ['name' => 'Task'])
        ->assertOk();

    expect(BoardItemActivity::where('item_id', $item->id)->count())->toBe(0);
});
