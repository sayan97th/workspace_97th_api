<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardVisit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Personal Favorites, My Work and the Home page's recently visited boards.
 */
function createPersonalBoard(array $attributes = []): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        ...$attributes,
    ]);
}

// ── Favorites ──────────────────────────────────────────────────────────────

test('starring a board only adds it to the current user favorites', function () {
    $board = createPersonalBoard();
    $starring_user = User::factory()->create();
    $other_user = User::factory()->create();

    $this->actingAs($starring_user, 'api')
        ->patchJson("/api/workspaces/{$board->workspace->slug}/navigation/{$board->id}", ['is_favorite' => true])
        ->assertOk();

    $this->actingAs($starring_user, 'api')->getJson('/api/favorites')
        ->assertOk()
        ->assertJsonPath('data.0.id', $board->id);

    $this->actingAs($other_user, 'api')->getJson('/api/favorites')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($other_user, 'api')->getJson("/api/boards/{$board->id}")
        ->assertJsonPath('is_favorite', false);
    $this->actingAs($starring_user, 'api')->getJson("/api/boards/{$board->id}")
        ->assertJsonPath('is_favorite', true);

    expect($board->fresh()->is_favorite)->toBeFalse();
});

test('favorites can be added, reordered and removed', function () {
    $user = User::factory()->create();
    $first = createPersonalBoard(['label' => 'First']);
    $second = createPersonalBoard(['label' => 'Second']);

    $this->actingAs($user, 'api')->putJson("/api/favorites/{$first->id}")->assertOk();
    $this->actingAs($user, 'api')->putJson("/api/favorites/{$second->id}")->assertOk();
    $this->actingAs($user, 'api')->putJson('/api/favorites/order', ['item_ids' => [$second->id, $first->id]])->assertOk();

    $this->actingAs($user, 'api')->getJson('/api/favorites')
        ->assertJsonPath('data.0.label', 'Second')
        ->assertJsonPath('data.1.label', 'First');

    $this->actingAs($user, 'api')->deleteJson("/api/favorites/{$second->id}")->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/favorites')->assertJsonCount(1, 'data');
});

test('an archived board drops out of favorites', function () {
    $user = User::factory()->create();
    $board = createPersonalBoard(['is_archived' => true]);

    $this->actingAs($user, 'api')->putJson("/api/favorites/{$board->id}")->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/favorites')->assertJsonCount(0, 'data');
});

// ── Recently visited ───────────────────────────────────────────────────────

test('opening a board records it as recently visited, newest first', function () {
    $user = User::factory()->create();
    $older = createPersonalBoard(['label' => 'Older']);
    $newer = createPersonalBoard(['label' => 'Newer']);

    $this->actingAs($user, 'api')->getJson("/api/boards/{$older->id}")->assertOk();
    $this->travel(1)->minutes();
    $this->actingAs($user, 'api')->getJson("/api/boards/{$newer->id}")->assertOk();

    $this->actingAs($user, 'api')->getJson('/api/home/recent-boards')
        ->assertOk()
        ->assertJsonPath('data.0.label', 'Newer')
        ->assertJsonPath('data.1.label', 'Older');

    expect(BoardVisit::where('user_id', $user->id)->count())->toBe(2);
});

test('opening the same board twice keeps a single visit row', function () {
    $user = User::factory()->create();
    $board = createPersonalBoard();

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}");
    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}");

    expect(BoardVisit::where('user_id', $user->id)->count())->toBe(1);
});

// ── My Work ────────────────────────────────────────────────────────────────

test('my work lists only the items the user is assigned to, with status and date', function () {
    $user = User::factory()->create();
    $board = createPersonalBoard(['label' => 'Launch']);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Sprint 1']);
    $view_id = $group->board_view_id;
    $people = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view_id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view_id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);
    $date = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view_id, 'type' => BoardColumn::TYPE_DATE]);

    $mine = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Write copy']);
    $mine->values()->createMany([
        ['column_id' => $people->id, 'value' => [$user->id]],
        ['column_id' => $status->id, 'value' => 'working'],
        ['column_id' => $date->id, 'value' => '2026-10-01'],
    ]);
    $finished = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Old task']);
    $finished->values()->createMany([
        ['column_id' => $people->id, 'value' => [$user->id]],
        ['column_id' => $status->id, 'value' => 'done'],
    ]);
    $someone_else = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Not mine']);
    $someone_else->values()->create(['column_id' => $people->id, 'value' => [$user->id + 1000]]);

    $response = $this->actingAs($user, 'api')->getJson('/api/my-work')->assertOk();
    $items = collect($response->json('items'))->keyBy('name');

    expect($items->keys()->sort()->values()->all())->toBe(['Old task', 'Write copy']);
    expect($items['Write copy']['status']['label'])->toBe('Working on it');
    expect($items['Write copy']['date']['value'])->toBe('2026-10-01');
    expect($items['Write copy']['board']['label'])->toBe('Launch');
    expect($items['Write copy']['is_done'])->toBeFalse();
    expect($items['Old task']['is_done'])->toBeTrue();
    expect($response->json("status_columns.{$status->id}.options"))->toHaveCount(2);
});

test('my work reads the end of a timeline as the due date', function () {
    $user = User::factory()->create();
    $board = createPersonalBoard();
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $people = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $timeline = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_TIMELINE]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $item->values()->createMany([
        ['column_id' => $people->id, 'value' => [$user->id]],
        ['column_id' => $timeline->id, 'value' => ['start' => '2026-10-01', 'end' => '2026-10-09']],
    ]);

    $this->actingAs($user, 'api')->getJson('/api/my-work')
        ->assertJsonPath('items.0.date.value', '2026-10-09')
        ->assertJsonPath('items.0.date_column.type', 'timeline');
});

test('my work skips archived items and archived boards', function () {
    $user = User::factory()->create();
    $board = createPersonalBoard(['is_archived' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $people = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $item->values()->create(['column_id' => $people->id, 'value' => [$user->id]]);

    $this->actingAs($user, 'api')->getJson('/api/my-work')->assertJsonCount(0, 'items');
});
