<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardGroup, 2: User}
 */
function createStarredFilterBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $user = User::factory()->create();
    $workspace->users()->attach($user->id, ['role' => 'member']);

    return [$board, $group, $user];
}

function createStarredFilterItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, bool $is_priority): BoardItem
{
    return $board->items()->create([
        'group_id' => $group->id,
        'name' => $name,
        'position' => $board->items()->count(),
        'is_priority' => $is_priority,
    ]);
}

/**
 * @param  array<string, mixed>  $filter_state
 * @return array<int, int>
 */
function starredFilterItemIds($test, WorkspaceNavigationItem $board, User $user, array $filter_state): array
{
    $query = http_build_query(['filter_state' => json_encode($filter_state), 'today' => '2026-09-25']);

    return collect($test->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/items?{$query}")->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

test('the starred quick filter returns only starred items', function () {
    [$board, $group, $user] = createStarredFilterBoard();
    $urgent = createStarredFilterItem($board, $group, 'Urgent', true);
    $launch = createStarredFilterItem($board, $group, 'Launch', true);
    $backlog = createStarredFilterItem($board, $group, 'Backlog', false);

    expect(starredFilterItemIds($this, $board, $user, ['quick_filter_selections' => ['__starred__' => ['checked']]]))
        ->toEqualCanonicalizing([$urgent->id, $launch->id]);

    expect(starredFilterItemIds($this, $board, $user, ['quick_filter_selections' => ['__starred__' => ['unchecked']]]))
        ->toBe([$backlog->id]);

    expect(starredFilterItemIds($this, $board, $user, ['quick_filter_exclusions' => ['__starred__' => ['checked']]]))
        ->toBe([$backlog->id]);
});

test('the starred advanced rule matches the row star', function () {
    [$board, $group, $user] = createStarredFilterBoard();
    $starred = createStarredFilterItem($board, $group, 'Starred', true);
    $plain = createStarredFilterItem($board, $group, 'Plain', false);

    $is_starred = ['column_id' => '__starred__', 'condition' => 'is_checked', 'value' => ''];
    expect(starredFilterItemIds($this, $board, $user, ['advanced_filter_rows' => [$is_starred]]))->toBe([$starred->id]);

    $is_not_starred = ['column_id' => '__starred__', 'condition' => 'is_unchecked', 'value' => ''];
    expect(starredFilterItemIds($this, $board, $user, ['advanced_filter_rows' => [$is_not_starred]]))->toBe([$plain->id]);
});
