<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Server-side narrowing for `GET /api/boards/{item}/items`: the `search` query
 * param, and the toolbar's Person/Quick/Advanced filters (`filter_state`).
 * Sorting and grouping stay client-side in `useBoardToolbar`, which also
 * re-applies every filter on top of whatever this returns.
 */
class BoardItemFilterService
{
    /**
     * Narrows an items query to rows whose name or any column value contains
     * the search term (case-insensitive substring match).
     *
     * @param  Builder<BoardItem>|HasMany<BoardItem, *>  $query
     * @return Builder<BoardItem>|HasMany<BoardItem, *>
     */
    public function applySearch(Builder|HasMany $query, ?string $search): Builder|HasMany
    {
        $term = trim((string) $search);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhereHas('values', function (Builder $value_query) use ($term) {
                    $value_query->where('value', 'LIKE', "%{$term}%");
                });
        });
    }

    /**
     * Narrows an items query to the rows matching a toolbar filter state (see
     * {@see BoardItemFilterEvaluator}). Cell values are stored as JSON, whose
     * query syntax differs between MySQL and SQLite, so the matching runs in
     * PHP over a lightweight pass (ids, names and values only, no counts,
     * children or mirrors), and the full query is then limited to the matching
     * ids. The heavy payload is only ever built for rows that are returned.
     *
     * @param  Builder<BoardItem>|HasMany<BoardItem, *>  $query
     * @param  array<string, mixed>|null  $filter_state
     * @param  string|null  $today  the viewer's own date (`YYYY-MM-DD`), so relative dates match their time zone
     * @param  string|null  $timezone  the viewer's IANA time zone, which turns Creation date and Last updated into their days
     * @return Builder<BoardItem>|HasMany<BoardItem, *>
     */
    public function applyFilterState(
        Builder|HasMany $query,
        ?array $filter_state,
        BoardView $view,
        ?int $current_user_id,
        ?string $today,
        ?string $timezone = null,
    ): Builder|HasMany {
        if ($filter_state === null || ! BoardItemFilterEvaluator::hasActiveFilters($filter_state)) {
            return $query;
        }

        $columns_by_scope = $view->columns()
            ->whereIn('scope', [BoardColumn::SCOPE_ITEM, BoardColumn::SCOPE_SUBITEM])
            ->get()
            ->groupBy('scope')
            ->map(fn ($columns) => $columns->keyBy(fn (BoardColumn $column) => (string) $column->id));

        $evaluator = new BoardItemFilterEvaluator(
            $columns_by_scope->get(BoardColumn::SCOPE_ITEM, collect()),
            $current_user_id,
            $this->resolveToday($today),
            $columns_by_scope->get(BoardColumn::SCOPE_SUBITEM, collect()),
            $this->teamMemberIds((array) ($filter_state['selected_team_ids'] ?? [])),
            $this->resolveTimezone($timezone),
        );

        $include_subitems = (bool) ($filter_state['include_subitems'] ?? false);
        $candidates = (clone $query)
            ->setEagerLoads([])
            ->select([
                'board_items.id',
                'board_items.group_id',
                'board_items.name',
                'board_items.created_by_id',
                'board_items.created_at',
                'board_items.updated_at',
            ])
            ->with($include_subitems ? ['values', 'children' => fn ($q) => $q->where('is_archived', false)->with('values')] : ['values'])
            ->get();

        $matching_ids = $candidates
            ->filter(fn (BoardItem $item) => $evaluator->matches($item, $filter_state))
            ->pluck('id')
            ->all();

        return $query->whereIn('board_items.id', $matching_ids);
    }

    /**
     * The members of each picked account team, keyed by team id as a string.
     *
     * @param  array<int, mixed>  $team_ids
     * @return array<string, array<int, int>>
     */
    private function teamMemberIds(array $team_ids): array
    {
        $team_ids = array_values(array_filter(array_map('intval', $team_ids)));
        if ($team_ids === []) {
            return [];
        }

        return DB::table('account_team_user')
            ->whereIn('account_team_id', $team_ids)
            ->get(['account_team_id', 'user_id'])
            ->groupBy('account_team_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($user_id) => (int) $user_id)->all())
            ->mapWithKeys(fn ($user_ids, $team_id) => [(string) $team_id => $user_ids])
            ->all();
    }

    private function resolveTimezone(?string $timezone): string
    {
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * GET `items/update-matches`: ids of the root items whose published updates
     * or replies, on the item itself or on one of its subitems, contain the term.
     * Uses `ESCAPE '!'` so `%` and `_` match literally on MySQL and SQLite alike.
     *
     * @param  Builder<BoardItem>|HasMany<BoardItem, *>  $root_query  the tab's live root items
     * @return array<int, int>
     */
    public function rootIdsWithMatchingUpdates(Builder|HasMany $root_query, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        $root_ids = (clone $root_query)->setEagerLoads([])->pluck('board_items.id');
        if ($root_ids->isEmpty()) {
            return [];
        }

        $matching_item_ids = DB::table('board_item_comments')
            ->join('board_items', 'board_items.id', '=', 'board_item_comments.item_id')
            ->where(fn ($q) => $q->whereIn('board_items.id', $root_ids)->orWhereIn('board_items.parent_id', $root_ids))
            ->whereNull('board_item_comments.deleted_at')
            // A scheduled update stays hidden from everyone until it is published.
            ->where(fn ($q) => $q->whereNull('board_item_comments.scheduled_at')->orWhere('board_item_comments.scheduled_at', '<=', now()))
            ->whereRaw("LOWER(board_item_comments.body) LIKE ? ESCAPE '!'", [$pattern])
            ->select(DB::raw('COALESCE(board_items.parent_id, board_items.id) as root_id'))
            ->distinct()
            ->pluck('root_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($matching_item_ids, $root_ids->map(fn ($id) => (int) $id)->all()));
    }

    private function resolveToday(?string $today): string
    {
        if (is_string($today) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1 && checkdate((int) substr($today, 5, 2), (int) substr($today, 8, 2), (int) substr($today, 0, 4))) {
            return $today;
        }

        return CarbonImmutable::today()->format('Y-m-d');
    }
}
