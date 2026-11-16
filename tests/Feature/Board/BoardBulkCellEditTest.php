<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Queue;

/**
 * Covers editing a cell on a row that is checked together with other rows:
 * like monday.com, the change lands on every checked item or subitem.
 * People (and other list cells) merge the added or removed entries into each
 * row's own list, every other column takes the same value.
 */
function createBulkCellEditBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $people_column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $first = $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]);
    $second = $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]);
    $third = $board->items()->create(['group_id' => $group->id, 'name' => 'Third', 'position' => 2]);

    return [$board, $group, $people_column, $first, $second, $third];
}

test('adding a person to checked items adds them to every item and keeps who each one had', function () {
    Queue::fake();

    [$board, , $column, $first, $second, $third] = createBulkCellEditBoard();
    $actor = User::factory()->create();
    $kept = User::factory()->create();
    $added = User::factory()->create();
    addBoardMembers($board, $kept, $added);
    $first->values()->create(['column_id' => $column->id, 'value' => [(string) $kept->id]]);

    $this->actingAs($actor, 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id, $second->id],
            'column_id' => $column->id,
            'added' => [(string) $added->id],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect($first->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBe([(string) $kept->id, (string) $added->id])
        ->and($second->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBe([(string) $added->id])
        ->and($third->fresh('values')->values->firstWhere('column_id', $column->id))->toBeNull();

    // Each item notifies on its own, like a single assignment would.
    expect(Notification::where('user_id', $added->id)->where('type', Notification::TYPE_ASSIGNED)->count())->toBe(2);
});

test('a person already on a checked item is not added twice, ids are matched as strings', function () {
    [$board, , $column, $first] = createBulkCellEditBoard();
    $person = User::factory()->create();
    addBoardMembers($board, $person);
    $first->values()->create(['column_id' => $column->id, 'value' => [$person->id]]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id],
            'column_id' => $column->id,
            'added' => [(string) $person->id],
        ])
        ->assertOk();

    expect($first->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBe([$person->id]);
});

test('removing a person from checked items removes only that person and empties a cell left without anyone', function () {
    [$board, , $column, $first, $second] = createBulkCellEditBoard();
    $kept = User::factory()->create();
    $removed = User::factory()->create();
    $first->values()->create(['column_id' => $column->id, 'value' => [(string) $kept->id, (string) $removed->id]]);
    $second->values()->create(['column_id' => $column->id, 'value' => [(string) $removed->id]]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id, $second->id],
            'column_id' => $column->id,
            'removed' => [(string) $removed->id],
        ])
        ->assertOk();

    expect($first->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBe([(string) $kept->id])
        ->and($second->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBeNull();
});

test('a status picked on a checked row is written to every checked row', function () {
    [$board, , , $first, $second, $third] = createBulkCellEditBoard();
    $status = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => BoardColumn::TYPE_STATUS]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id, $second->id, $third->id],
            'column_id' => $status->id,
            'value' => 'done',
        ])
        ->assertOk()
        ->assertJsonCount(3, 'items');

    foreach ([$first, $second, $third] as $board_item) {
        expect($board_item->fresh('values')->values->firstWhere('column_id', $status->id)->value)->toBe('done');
    }
});

test('people added to checked subitems land on every subitem', function () {
    [$board, $group, , $first, $second] = createBulkCellEditBoard();
    $sub_people = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'type' => BoardColumn::TYPE_PEOPLE,
        'scope' => BoardColumn::SCOPE_SUBITEM,
    ]);
    $first_sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $first->id, 'name' => 'First sub', 'position' => 0]);
    $second_sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $second->id, 'name' => 'Second sub', 'position' => 0]);
    $person = User::factory()->create();
    addBoardMembers($board, $person);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first_sub->id, $second_sub->id],
            'column_id' => $sub_people->id,
            'added' => [(string) $person->id],
        ])
        ->assertOk();

    expect($first_sub->fresh('values')->values->firstWhere('column_id', $sub_people->id)->value)->toBe([(string) $person->id])
        ->and($second_sub->fresh('values')->values->firstWhere('column_id', $sub_people->id)->value)->toBe([(string) $person->id]);
});

test('a bulk cell edit must carry a value or entries to add or remove, not both', function () {
    [$board, , $column, $first] = createBulkCellEditBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", ['item_ids' => [$first->id], 'column_id' => $column->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('value');

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id],
            'column_id' => $column->id,
            'value' => null,
            'added' => ['1'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('value');
});

test('clearing a cell on checked rows still works with a null value', function () {
    [$board, , $column, $first, $second] = createBulkCellEditBoard();
    $first->values()->create(['column_id' => $column->id, 'value' => ['1']]);

    $this->actingAs(User::factory()->create(), 'api')
        ->patchJson("/api/boards/{$board->id}/items/values", [
            'item_ids' => [$first->id, $second->id],
            'column_id' => $column->id,
            'value' => null,
        ])
        ->assertOk();

    expect($first->fresh('values')->values->firstWhere('column_id', $column->id)->value)->toBeNull();
});
