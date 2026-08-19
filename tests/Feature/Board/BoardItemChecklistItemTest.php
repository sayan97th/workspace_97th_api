<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createChecklistTestItem(): BoardItem
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

test('a subtask can be added to an item, appended after existing ones', function () {
    $item = createChecklistTestItem();
    $item->checklistItems()->create(['label' => 'First', 'position' => 1]);
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/checklist-items",
        ['label' => 'Second']
    );

    $response->assertCreated()
        ->assertJsonPath('checklist_item.label', 'Second')
        ->assertJsonPath('checklist_item.is_done', false)
        ->assertJsonPath('checklist_item.position', 2);

    $this->assertDatabaseHas('board_item_checklist_items', ['item_id' => $item->id, 'label' => 'Second']);
});

test('a subtask requires a label', function () {
    $item = createChecklistTestItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');
});

test('a subtask can be toggled done and back', function () {
    $item = createChecklistTestItem();
    $checklist_item = $item->checklistItems()->create(['label' => 'Draft summary', 'position' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items/{$checklist_item->id}", ['is_done' => true])
        ->assertOk()
        ->assertJsonPath('checklist_item.is_done', true);

    $this->assertDatabaseHas('board_item_checklist_items', ['id' => $checklist_item->id, 'is_done' => true]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items/{$checklist_item->id}", ['is_done' => false])
        ->assertOk()
        ->assertJsonPath('checklist_item.is_done', false);
});

test('a subtask can be renamed', function () {
    $item = createChecklistTestItem();
    $checklist_item = $item->checklistItems()->create(['label' => 'Old label', 'position' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items/{$checklist_item->id}", ['label' => 'New label'])
        ->assertOk()
        ->assertJsonPath('checklist_item.label', 'New label');

    $this->assertDatabaseHas('board_item_checklist_items', ['id' => $checklist_item->id, 'label' => 'New label']);
});

test('a subtask can be deleted', function () {
    $item = createChecklistTestItem();
    $checklist_item = $item->checklistItems()->create(['label' => 'Temp', 'position' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items/{$checklist_item->id}")
        ->assertOk();

    $this->assertDatabaseMissing('board_item_checklist_items', ['id' => $checklist_item->id]);
});

test('a subtask belonging to a different item is not reachable', function () {
    $item = createChecklistTestItem();
    $other_item = createChecklistTestItem();
    $checklist_item = $other_item->checklistItems()->create(['label' => 'Not yours', 'position' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/checklist-items/{$checklist_item->id}", ['label' => 'Hijacked'])
        ->assertNotFound();
});

test('the item index returns checklist totals and the item detail returns the full list', function () {
    $item = createChecklistTestItem();
    $item->checklistItems()->create(['label' => 'Done one', 'is_done' => true, 'position' => 1]);
    $item->checklistItems()->create(['label' => 'Pending one', 'is_done' => false, 'position' => 2]);
    $user = User::factory()->create();

    $index_response = $this->actingAs($user, 'api')->getJson("/api/boards/{$item->board_id}/items");
    $index_response->assertOk();
    $row = collect($index_response->json('data'))->firstWhere('id', $item->id);
    expect($row['checklist_total_count'])->toBe(2);
    expect($row['checklist_done_count'])->toBe(1);

    $show_response = $this->actingAs($user, 'api')->getJson("/api/boards/{$item->board_id}/items/{$item->id}");
    $show_response->assertOk()->assertJsonCount(2, 'checklist_items');
});
