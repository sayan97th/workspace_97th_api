<?php

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\BoardItemValue;
use App\Models\BoardView;
use App\Models\BoardVisit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityFeed;
use App\Services\Board\BoardItemTransferService;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardGroup, 3: BoardColumn}
 */
function activityLogBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id, 'type' => WorkspaceNavigationItem::TYPE_LEAF, 'parent_id' => null, 'label' => 'Launch plan']);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'name' => 'To do', 'accent_color' => '#579bfc']);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'scope' => BoardColumn::SCOPE_ITEM,
        'config' => ['options' => [
            ['id' => 'working', 'label' => 'Working on it', 'color' => '#fdab3d'],
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
        ]],
    ]);

    return [$board, $view, $group, $status];
}

function activityLogFeed(User $user, WorkspaceNavigationItem $board, string $query = ''): array
{
    return test()->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/activity-log/feed{$query}")->assertOk()->json();
}

test('the feed merges cell changes and board events newest first, with label colors', function () {
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();

    $created = test()->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'Write brief'])->assertCreated();
    $item_id = $created->json('item.id');
    $this->travel(1)->seconds();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item_id}/values", ['values' => [(string) $status->id => 'done']])->assertOk();

    $feed = activityLogFeed($user, $board, '?with_facets=1');

    expect($feed['data'])->toHaveCount(2)
        ->and($feed['data'][0]['source'])->toBe('cell')
        ->and($feed['data'][0]['new_value'])->toBe(['text' => 'Done', 'color' => '#00c875'])
        ->and($feed['data'][0]['old_value'])->toBeNull()
        ->and($feed['data'][0]['can_undo'])->toBeTrue()
        ->and($feed['data'][1]['action'])->toBe(BoardActivityLog::ACTION_ITEM_CREATED)
        ->and($feed['data'][1]['item']['name'])->toBe('Write brief')
        ->and($feed['meta']['facets']['columns'][0]['label'])->toBe('Status')
        ->and($feed['meta']['facets']['people'][0]['id'])->toBe($user->id);
});

test('the feed pages with a cursor without skipping or repeating entries', function () {
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    foreach (['working', 'done', 'working', 'done', 'working'] as $value) {
        $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => $value]])->assertOk();
    }
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/groups", ['name' => 'Done'])->assertCreated();

    $first = activityLogFeed($user, $board, '?per_page=4');
    $second = activityLogFeed($user, $board, '?per_page=4&cursor='.$first['meta']['next_cursor']);

    $keys = [...array_column($first['data'], 'key'), ...array_column($second['data'], 'key')];
    expect($keys)->toHaveCount(6)
        ->and(array_unique($keys))->toHaveCount(6)
        ->and($second['meta']['next_cursor'])->toBeNull()
        ->and($second['meta']['facets'])->toBeNull();
});

test('filters narrow the feed by event type, column, person and search', function () {
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Hero banner', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'done']])->assertOk();
    $this->actingAs($other, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['name' => 'Backlog'])->assertOk();

    expect(activityLogFeed($user, $board, '?event_types[]=groups')['data'])->toHaveCount(1)
        ->and(activityLogFeed($user, $board, "?column_ids[]={$status->id}")['data'][0]['column']['label'])->toBe('Status')
        ->and(activityLogFeed($user, $board, "?user_ids[]={$other->id}")['data'][0]['action'])->toBe(BoardActivityLog::ACTION_GROUP_RENAMED)
        ->and(activityLogFeed($user, $board, '?search=hero')['data'])->toHaveCount(1)
        ->and(activityLogFeed($user, $board, '?search=nothing-like-this')['data'])->toHaveCount(0);

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/activity-log/feed?event_types[]=bogus")->assertUnprocessable();
});

test('undo writes the previous cell value back and can not run twice', function () {
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'working']])->assertOk();
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'done']])->assertOk();

    $entry = BoardItemActivity::where('item_id', $item->id)->latest('id')->firstOrFail();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/activity-log/cell/{$entry->id}/undo")
        ->assertOk()
        ->assertJsonPath('item_ids', [$item->id]);

    expect(BoardItemValue::where('item_id', $item->id)->where('column_id', $status->id)->value('value'))->toBe('working')
        ->and($entry->fresh()->undone_at)->not->toBeNull();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/activity-log/cell/{$entry->id}/undo")->assertUnprocessable();
});

test('undo brings back an item moved to the trash and renames a group back', function () {
    [$board, , $group] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $this->actingAs($user, 'api')->deleteJson("/api/boards/{$board->id}/items/{$item->id}")->assertOk();
    $trashed = BoardActivityLog::where('action', BoardActivityLog::ACTION_ITEM_TRASHED)->firstOrFail();
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/activity-log/board/{$trashed->id}/undo")->assertOk();
    expect(BoardItem::find($item->id))->not->toBeNull();

    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/groups/{$group->id}", ['name' => 'Renamed'])->assertOk();
    $renamed = BoardActivityLog::where('action', BoardActivityLog::ACTION_GROUP_RENAMED)->firstOrFail();
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/activity-log/board/{$renamed->id}/undo")->assertOk();
    expect($group->fresh()->name)->toBe('To do');
});

test('an entry of another board can not be undone through this board', function () {
    [$board, , $group, $status] = activityLogBoard();
    [$other_board] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'done']])->assertOk();
    $entry = BoardItemActivity::where('item_id', $item->id)->firstOrFail();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$other_board->id}/activity-log/cell/{$entry->id}/undo")->assertNotFound();
});

test('last viewed lists who opened the board, most recent first', function () {
    [$board] = activityLogBoard();
    $first = User::factory()->create(['first_name' => 'Ana']);
    $second = User::factory()->create(['first_name' => 'Ben']);
    BoardVisit::create(['user_id' => $first->id, 'board_id' => $board->id, 'visited_at' => now()->subDay()]);
    BoardVisit::create(['user_id' => $second->id, 'board_id' => $board->id, 'visited_at' => now()]);

    $viewers = $this->actingAs($first, 'api')->getJson("/api/boards/{$board->id}/activity-log/viewers")->assertOk()->json('data');

    expect(array_column(array_column($viewers, 'user'), 'id'))->toBe([$second->id, $first->id]);
});

test('the updates tab lists the updates posted on the board items', function () {
    [$board, , $group] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $item->comments()->create(['user_id' => $user->id, 'body' => '<p>Kick off</p>']);

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/activity-log/updates")
        ->assertOk()
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item.name', 'Task')
        ->assertJsonPath('data.0.author.id', $user->id);
});

test('a filtered walk through small scan windows returns every match once, in order', function () {
    app()->instance(BoardActivityFeed::class, new BoardActivityFeed(3));
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();
    $match = $board->items()->create(['group_id' => $group->id, 'name' => 'Needle', 'position' => 0]);
    $noise = $board->items()->create(['group_id' => $group->id, 'name' => 'Hay', 'position' => 1]);

    foreach (range(1, 12) as $round) {
        $this->travel(1)->seconds();
        $target = $round % 3 === 0 ? $match : $noise;
        $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$target->id}/values", ['values' => [(string) $status->id => $round % 2 ? 'done' : 'working']])->assertOk();
    }

    $keys = [];
    $cursor = null;
    $requests = 0;
    do {
        $page = activityLogFeed($user, $board, '?per_page=2&search=needle'.($cursor ? "&cursor={$cursor}" : ''));
        $keys = [...$keys, ...array_column($page['data'], 'key')];
        $cursor = $page['meta']['next_cursor'];
        $requests++;
    } while ($cursor !== null && $requests < 50);

    $expected = BoardItemActivity::where('item_id', $match->id)->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->map(fn ($id) => "cell-{$id}")->all();
    expect($keys)->toBe($expected)->and($requests)->toBeGreaterThan(2);
});

test('facets are only computed when the drawer asks for them', function () {
    [$board] = activityLogBoard();
    $user = User::factory()->create();

    expect(activityLogFeed($user, $board)['meta']['facets'])->toBeNull()
        ->and(activityLogFeed($user, $board, '?with_facets=1')['meta']['facets']['columns'])->toHaveCount(1);
});

test('the history of an item moved to another board follows it', function () {
    [$board, , $group, $status] = activityLogBoard();
    $user = User::factory()->create();
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
    $this->actingAs($user, 'api')->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $status->id => 'done']])->assertOk();

    expect(BoardItemActivity::where('item_id', $item->id)->value('board_id'))->toBe($board->id);

    $target = BoardGroup::factory()->create(['board_id' => WorkspaceNavigationItem::factory()->create(['workspace_id' => $board->workspace_id, 'type' => WorkspaceNavigationItem::TYPE_LEAF])->id]);
    app(BoardItemTransferService::class)->moveToBoard($item, $target);

    expect(BoardItemActivity::where('item_id', $item->id)->value('board_id'))->toBe($target->board_id);
});
