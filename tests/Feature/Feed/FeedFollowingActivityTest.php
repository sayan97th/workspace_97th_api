<?php

use App\Models\BoardColumn;
use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: BoardItem, 1: BoardItem, 2: Workspace}
 */
function createFollowTestBoard(User $member, string $role = 'member'): array
{
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => $role]);

    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [
        $board->items()->create(['group_id' => $group->id, 'name' => 'First', 'position' => 0]),
        $board->items()->create(['group_id' => $group->id, 'name' => 'Second', 'position' => 1]),
        $workspace,
    ];
}

test('the following tab lists updates on followed items and boards only', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    [$first, $second] = createFollowTestBoard($user);
    [$other_board_item] = createFollowTestBoard($user);

    BoardItemComment::create(['item_id' => $first->id, 'user_id' => $author->id, 'body' => 'On first']);
    BoardItemComment::create(['item_id' => $second->id, 'user_id' => $author->id, 'body' => 'On second']);
    BoardItemComment::create(['item_id' => $other_board_item->id, 'user_id' => $author->id, 'body' => 'Elsewhere']);
    BoardComment::create(['board_id' => $first->board_id, 'user_id' => $author->id, 'body' => 'Board wide']);

    $this->actingAs($user, 'api')->getJson('/api/feed/updates?tab=following')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($user, 'api')->postJson('/api/feed/follows', ['type' => 'item', 'id' => $first->id])->assertCreated();
    $this->actingAs($user, 'api')->postJson('/api/feed/follows', ['type' => 'item', 'id' => $first->id])->assertCreated();
    expect($user->feedFollows()->count())->toBe(1);

    $following = $this->actingAs($user, 'api')->getJson('/api/feed/updates?tab=following')->assertOk();
    expect(collect($following->json('data'))->pluck('body')->all())->toBe(['On first'])
        ->and($following->json('data.0.is_following_item'))->toBeTrue()
        ->and($following->json('data.0.is_following_board'))->toBeFalse();

    // Following the board covers its discussion and every item on it, but not other boards.
    $this->actingAs($user, 'api')->postJson('/api/feed/follows', ['type' => 'board', 'id' => $first->board_id])->assertCreated();
    $bodies = collect($this->actingAs($user, 'api')->getJson('/api/feed/updates?tab=following')->json('data'))->pluck('body')->sort()->values()->all();
    expect($bodies)->toBe(['Board wide', 'On first', 'On second']);

    $this->actingAs($user, 'api')->getJson('/api/feed/follows')
        ->assertOk()
        ->assertJsonPath('data.boards.0.id', $first->board_id)
        ->assertJsonPath('data.items.0.id', $first->id);

    $this->actingAs($user, 'api')->deleteJson("/api/feed/follows/board/{$first->board_id}")->assertOk()->assertJsonPath('data.following', false);
    $this->actingAs($user, 'api')->deleteJson("/api/feed/follows/item/{$first->id}")->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/feed/updates?tab=following')->assertOk()->assertJsonCount(0, 'data');
});

test('following something outside the viewers workspaces is refused', function () {
    $owner = User::factory()->create();
    [$item] = createFollowTestBoard($owner);

    $this->actingAs(User::factory()->create(), 'api')->postJson('/api/feed/follows', ['type' => 'item', 'id' => $item->id])->assertForbidden();
    $this->actingAs(User::factory()->create(), 'api')->postJson('/api/feed/follows', ['type' => 'board', 'id' => $item->board_id])->assertForbidden();
});

test('the pinned tab lists every pinned update across boards without paging', function () {
    $user = User::factory()->create();
    [$first] = createFollowTestBoard($user);
    [$other] = createFollowTestBoard($user);

    BoardItemComment::create(['item_id' => $first->id, 'user_id' => $user->id, 'body' => 'Pinned one', 'pinned' => true]);
    BoardItemComment::create(['item_id' => $other->id, 'user_id' => $user->id, 'body' => 'Pinned two', 'pinned' => true]);
    BoardItemComment::create(['item_id' => $first->id, 'user_id' => $user->id, 'body' => 'Plain']);

    $response = $this->actingAs($user, 'api')->getJson('/api/feed/updates?tab=pinned')->assertOk();

    expect(collect($response->json('data'))->pluck('body')->sort()->values()->all())->toBe(['Pinned one', 'Pinned two'])
        ->and($response->json('meta.has_more'))->toBeFalse();
});

test('an update carries the item changes made since the previous update', function () {
    $user = User::factory()->create();
    [$item] = createFollowTestBoard($user);
    $group = $item->group;
    $status = BoardColumn::factory()->create([
        'board_id' => $item->board_id,
        'board_view_id' => $group->board_view_id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'config' => ['options' => [['id' => 'done', 'label' => 'Done', 'color' => '#00c875'], ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c']]],
    ]);
    $values_url = "/api/boards/{$item->board_id}/items/{$item->id}/values";
    $comments_url = "/api/boards/{$item->board_id}/items/{$item->id}/comments";

    $this->actingAs($user, 'api')->patchJson($values_url, ['values' => [(string) $status->id => 'stuck']])->assertOk();
    $this->actingAs($user, 'api')->postJson($comments_url, ['body' => 'First update'])->assertCreated();

    // An unchanged value is not a change.
    $this->travel(2)->minutes();
    $this->actingAs($user, 'api')->patchJson($values_url, ['values' => [(string) $status->id => 'stuck']])->assertOk();
    $this->actingAs($user, 'api')->patchJson($values_url, ['values' => [(string) $status->id => 'done']])->assertOk();
    $this->actingAs($user, 'api')->postJson($comments_url, ['body' => 'Second update'])->assertCreated();

    $updates = collect($this->actingAs($user, 'api')->getJson('/api/feed/updates')->assertOk()->json('data'))->keyBy('body');

    expect($updates['First update']['activity_total'])->toBe(1)
        ->and($updates['First update']['activity'][0]['new_display'])->toBe('Stuck')
        ->and($updates['Second update']['activity_total'])->toBe(1)
        ->and($updates['Second update']['activity'][0])->toMatchArray([
            'column_label' => 'Status',
            'old_display' => 'Stuck',
            'new_display' => 'Done',
        ]);
});

test('creating an item with starting values does not log them as changes', function () {
    $user = User::factory()->create();
    [$item, , $workspace] = createFollowTestBoard($user);
    $column = BoardColumn::factory()->create([
        'board_id' => $item->board_id,
        'board_view_id' => $item->group->board_view_id,
        'type' => BoardColumn::TYPE_TEXT,
        'label' => 'Notes',
    ]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$item->board_id}/items", [
        'group_id' => $item->group_id,
        'name' => 'Fresh',
        'values' => [(string) $column->id => 'hello'],
    ])->assertCreated();

    $this->assertDatabaseCount('board_item_activities', 0);
});
