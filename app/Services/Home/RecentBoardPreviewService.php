<?php

namespace App\Services\Home;

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use Illuminate\Support\Collection;

/**
 * The small board snapshot the Home page draws on each "Recently visited"
 * card: the first groups of the board's primary view, with their colors and
 * how many items each one holds.
 *
 * Built for many boards at once with a fixed number of queries, so the Home
 * page stays fast no matter how many boards it lists.
 */
class RecentBoardPreviewService
{
    /** How many groups a card draws. */
    public const GROUP_LIMIT = 4;

    /**
     * @param  array<int, int>  $board_ids
     * @return array<int, array{item_count: int, group_count: int, groups: array<int, array{id: int, name: string, color: string|null, item_count: int}>}>
     */
    public function previewsFor(array $board_ids): array
    {
        if ($board_ids === []) {
            return [];
        }

        $primary_view_ids = BoardView::query()
            ->whereIn('board_id', $board_ids)
            ->where('is_primary', true)
            ->pluck('id', 'board_id');

        $groups = BoardGroup::query()
            ->whereIn('board_id', $board_ids)
            ->whereIn('board_view_id', $primary_view_ids->values())
            ->where('is_archived', false)
            ->orderBy('position')
            ->get(['id', 'board_id', 'name', 'accent_color']);

        $item_counts = BoardItem::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->selectRaw('group_id, count(*) as aggregate')
            ->groupBy('group_id')
            ->pluck('aggregate', 'group_id');

        return collect($board_ids)
            ->mapWithKeys(fn (int $board_id) => [$board_id => $this->previewOf($groups->where('board_id', $board_id)->values(), $item_counts)])
            ->all();
    }

    /**
     * @param  Collection<int, BoardGroup>  $board_groups
     * @param  Collection<int|string, int|string>  $item_counts
     * @return array{item_count: int, group_count: int, groups: array<int, array{id: int, name: string, color: string|null, item_count: int}>}
     */
    private function previewOf(Collection $board_groups, Collection $item_counts): array
    {
        $count_of = fn (BoardGroup $group): int => (int) ($item_counts[$group->id] ?? 0);

        return [
            'item_count' => $board_groups->sum($count_of),
            'group_count' => $board_groups->count(),
            'groups' => $board_groups
                ->take(self::GROUP_LIMIT)
                ->map(fn (BoardGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'color' => $group->accent_color,
                    'item_count' => $count_of($group),
                ])
                ->values()
                ->all(),
        ];
    }
}
