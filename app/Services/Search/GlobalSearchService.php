<?php

namespace App\Services\Search;

use App\Http\Controllers\Workspace\WorkspaceController;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs the top bar's "Search for anything..." box (`GET /api/search`).
 *
 * Searches three kinds of things, each capped to `$limit` rows and returned in
 * its own group so the dropdown can render them as sections:
 *
 * - workspaces, by name (every signed-in user sees the full workspace
 *   directory, see {@see WorkspaceController::index()});
 * - boards, docs and dashboards, by label, using the same visibility rules as
 *   the Content tab plus the board privacy rule in {@see visibleBoardsQuery()};
 * - items and subitems, by name, limited to the boards the user can see.
 *
 * Search only reads and never touches the boards' own filter/sort state.
 */
class GlobalSearchService
{
    public const MIN_TERM_LENGTH = 2;

    public const MAX_TERM_LENGTH = 100;

    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 10;

    /** Escape character used in every LIKE pattern, portable across MySQL, PostgreSQL and SQLite. */
    private const LIKE_ESCAPE = '!';

    /**
     * @return array{
     *     workspaces: array<int, array<string, mixed>>,
     *     boards: array<int, array<string, mixed>>,
     *     items: array<int, array<string, mixed>>,
     * }
     */
    public function search(User $user, string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        $term = trim($term);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return [
            'workspaces' => $this->searchWorkspaces($term, $limit),
            'boards' => $this->searchBoards($user, $term, $limit),
            'items' => $this->searchItems($user, $term, $limit),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchWorkspaces(string $term, int $limit): array
    {
        return $this->applyTermFilter(Workspace::query(), 'name', $term)
            ->limit($limit)
            ->get()
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'mono' => $workspace->mono,
                'color' => $workspace->color,
                'avatar_url' => $workspace->avatar_thumbnail_url ?? $workspace->avatar_url,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchBoards(User $user, string $term, int $limit): array
    {
        return $this->applyTermFilter($this->visibleBoardsQuery($user), 'label', $term)
            ->with('workspace:id,name,slug')
            ->limit($limit)
            ->get()
            ->map(fn (WorkspaceNavigationItem $board) => [
                'id' => $board->id,
                'label' => $board->label,
                'asset_type' => $board->assetType(),
                'board_type' => $board->board_type,
                'icon' => $board->icon,
                'workspace' => $this->presentWorkspace($board->workspace),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchItems(User $user, string $term, int $limit): array
    {
        $items = BoardItem::query()
            ->whereIn('board_id', $this->visibleBoardsQuery($user)->select('id'))
            ->where('is_archived', false)
            ->whereHas('group', fn ($query) => $query->where('is_archived', false));

        return $this->applyTermFilter($items, 'name', $term)
            ->with(['board:id,workspace_id,label', 'board.workspace:id,name,slug', 'group:id,board_view_id', 'parent:id,name'])
            ->limit($limit)
            ->get()
            ->map(fn (BoardItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'is_subitem' => $item->parent_id !== null,
                'parent_name' => $item->parent?->name,
                'view_id' => $item->group->board_view_id,
                'board' => [
                    'id' => $item->board->id,
                    'label' => $item->board->label,
                ],
                'workspace' => $this->presentWorkspace($item->board->workspace),
            ])
            ->all();
    }

    /**
     * Every board/doc leaf the user may open, see {@see BoardVisibility::query()}.
     * Also used as a subquery to scope item search.
     *
     * @return Builder<WorkspaceNavigationItem>
     */
    private function visibleBoardsQuery(User $user): Builder
    {
        return BoardVisibility::query($user);
    }

    /**
     * Narrows `$query` to rows whose `$column` contains `$term` (case-insensitive
     * substring match) and orders the best matches first: rows that start with
     * the term, then the rest alphabetically. `%`, `_` and the escape character
     * inside the term are escaped so they match literally.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function applyTermFilter(Builder $query, string $column, string $term): Builder
    {
        $escaped_term = strtr($term, [
            self::LIKE_ESCAPE => self::LIKE_ESCAPE.self::LIKE_ESCAPE,
            '%' => self::LIKE_ESCAPE.'%',
            '_' => self::LIKE_ESCAPE.'_',
        ]);
        $escape_clause = "ESCAPE '".self::LIKE_ESCAPE."'";

        return $query
            ->whereRaw("{$column} LIKE ? {$escape_clause}", ["%{$escaped_term}%"])
            ->orderByRaw("CASE WHEN {$column} LIKE ? {$escape_clause} THEN 0 ELSE 1 END", ["{$escaped_term}%"])
            ->orderBy($column);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWorkspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'slug' => $workspace->slug,
            'name' => $workspace->name,
        ];
    }
}
