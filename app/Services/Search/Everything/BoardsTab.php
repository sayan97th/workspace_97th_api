<?php

namespace App\Services\Search\Everything;

use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Search\TagCatalog;
use App\Support\LikeTerm;
use App\Support\VisibleBoards;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Cross Boards": the boards the user can open. Without a term every board is listed. With a
 * term a board matches by its name or because some of its items match, by item name or by a tag
 * on the item, and up to {@see MATCHED_ITEMS_PER_BOARD} of those items come along. A term that
 * starts with "#" (what clicking a tag in a Tags cell searches for) only matches by tag, so
 * "#seo" lists exactly the boards with items tagged #seo.
 */
final class BoardsTab implements SearchTab
{
    public const MATCHED_ITEMS_PER_BOARD = 5;

    public function __construct(
        private readonly TagCatalog $tag_catalog,
    ) {}

    public function count(SearchCriteria $criteria): int
    {
        return $this->boardsQuery($criteria)->count();
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        $query = $this->boardsQuery($criteria)->with('workspace');

        if ($criteria->sortsByTime()) {
            $query->orderByDesc('updated_at');
        } else {
            LikeTerm::orderByPrefix($query, 'label', $criteria->term)->orderBy('label');
        }

        $total = $query->clone()->count();
        $boards = $query->orderBy('id')->offset($offset)->limit($limit)->get();
        [$items_by_board, $counts_by_board] = $this->matchedItems($criteria, $boards->pluck('id')->all());

        $data = $boards->map(fn (WorkspaceNavigationItem $board) => [
            ...SearchPresenter::board($board),
            'matched_items' => $items_by_board[$board->id] ?? [],
            'matched_item_count' => $counts_by_board[$board->id] ?? 0,
        ])->all();

        return ['data' => $data, 'total' => $total];
    }

    public function facets(SearchCriteria $criteria): array
    {
        return [];
    }

    /**
     * @return Builder<WorkspaceNavigationItem>
     */
    private function boardsQuery(SearchCriteria $criteria): Builder
    {
        $query = VisibleBoards::query($criteria->user, $criteria->include_archived)
            ->where(fn (Builder $boards) => $boards->whereNull('view_key')
                ->orWhereNotIn('view_key', collect(WorkspaceNavigationItem::ASSET_TYPE_VIEW_KEYS)->flatten()->all()));

        $criteria->applyDateRange($query, 'updated_at');

        if (! $criteria->hasTerm()) {
            return $query;
        }

        $matching_items = $this->matchingItemsQuery($criteria);

        if ($this->isTagTerm($criteria)) {
            return $query->whereIn('id', $matching_items->select('board_id'));
        }

        return $query->where(fn (Builder $boards) => LikeTerm::whereContains($boards, ['label'], $criteria->term)
            ->orWhereIn('id', $matching_items->select('board_id')));
    }

    private function isTagTerm(SearchCriteria $criteria): bool
    {
        return str_starts_with($criteria->term, '#');
    }

    /**
     * Items on visible boards whose name contains the term or that carry a matching tag.
     *
     * @return Builder<BoardItem>
     */
    private function matchingItemsQuery(SearchCriteria $criteria): Builder
    {
        $tagged_item_ids = $this->tag_catalog->itemIdsForTags(
            $criteria->user,
            $this->tag_catalog->tagIdsMatching($criteria->user, $criteria->term),
            $criteria->include_archived,
        );

        $query = BoardItem::query()->whereIn('id', $criteria->itemIds());

        if ($this->isTagTerm($criteria)) {
            return $query->whereIn('id', $tagged_item_ids);
        }

        return $query->where(fn (Builder $items) => LikeTerm::whereContains($items, ['name'], $criteria->term)
            ->when($tagged_item_ids !== [], fn (Builder $tagged) => $tagged->orWhereIn('id', $tagged_item_ids)));
    }

    /**
     * The first matched items of each board on the page, plus how many matched in total.
     *
     * @param  array<int, int>  $board_ids
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, int>}
     */
    private function matchedItems(SearchCriteria $criteria, array $board_ids): array
    {
        if (! $criteria->hasTerm() || $board_ids === []) {
            return [[], []];
        }

        $matching = $this->matchingItemsQuery($criteria)->whereIn('board_id', $board_ids);

        $counts = $matching->clone()
            ->toBase()
            ->selectRaw('board_id, COUNT(*) as aggregate')
            ->groupBy('board_id')
            ->pluck('aggregate', 'board_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        $items = [];
        $matching->with('group:id,board_view_id')
            ->orderBy('board_id')
            ->orderBy('position')
            ->get(['id', 'board_id', 'group_id', 'parent_id', 'name'])
            ->groupBy('board_id')
            ->each(function ($board_items, $board_id) use (&$items) {
                $items[$board_id] = $board_items->take(self::MATCHED_ITEMS_PER_BOARD)
                    ->map(fn (BoardItem $item) => SearchPresenter::item($item))
                    ->values()
                    ->all();
            });

        return [$items, $counts];
    }
}
