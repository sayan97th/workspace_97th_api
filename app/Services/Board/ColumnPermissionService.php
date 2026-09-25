<?php

namespace App\Services\Board;

use App\Http\Resources\BoardItemResource;
use App\Models\BoardColumn;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardEditGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * monday.com's column permissions. A column's `view_restriction` and
 * `edit_restriction` are either `null` (everyone) or
 * `{user_ids: int[], team_ids: int[]}`: only those people, the members of
 * those teams and the board owners pass. An anonymous viewer (a public share
 * link) never passes a restriction.
 *
 * Registered as a scoped singleton so the lookups below are computed once
 * per request, even when {@see BoardItemResource} asks
 * for every row of a board.
 */
class ColumnPermissionService
{
    /** @var array<string, array<int, int>> */
    private array $hidden_column_ids = [];

    /** @var array<int, array<int, int>> */
    private array $team_ids_by_user = [];

    /** @var array<string, bool> */
    private array $owner_by_key = [];

    /**
     * Normalizes a restriction payload coming from a request: `null` or an
     * empty selection both mean "no restriction".
     *
     * @param  array<string, mixed>|null  $restriction
     * @return array{user_ids: array<int, int>, team_ids: array<int, int>}|null
     */
    public static function normalize(?array $restriction): ?array
    {
        if ($restriction === null) {
            return null;
        }

        $user_ids = array_values(array_unique(array_map('intval', $restriction['user_ids'] ?? [])));
        $team_ids = array_values(array_unique(array_map('intval', $restriction['team_ids'] ?? [])));

        // An enabled restriction with nobody picked still means "board owners
        // only", so it is kept as an empty selection rather than dropped.
        return ['user_ids' => $user_ids, 'team_ids' => $team_ids];
    }

    /**
     * Whether `$user` may see `$column`'s values.
     */
    public function canView(BoardColumn $column, ?User $user, WorkspaceNavigationItem $board): bool
    {
        return $this->passes($column->view_restriction, $user, $board);
    }

    /**
     * Whether `$user` may change `$column`'s values. Seeing a column is a
     * prerequisite for editing it.
     */
    public function canEdit(BoardColumn $column, ?User $user, WorkspaceNavigationItem $board): bool
    {
        return $this->canView($column, $user, $board) && $this->passes($column->edit_restriction, $user, $board);
    }

    /**
     * Aborts with a 403 when `$user` may not change any of `$column_ids`.
     *
     * @param  array<int, int|string>  $column_ids
     */
    public function authorizeEdit(WorkspaceNavigationItem $board, User $user, array $column_ids): void
    {
        if ($column_ids === []) {
            return;
        }

        $columns = BoardColumn::query()
            ->where('board_id', $board->id)
            ->whereIn('id', array_map('intval', $column_ids))
            ->where(fn ($query) => $query->whereNotNull('edit_restriction')->orWhereNotNull('view_restriction'))
            ->get();

        foreach ($columns as $column) {
            if (! $this->canEdit($column, $user, $board)) {
                throw ValidationException::withMessages([
                    'values' => "You don't have permission to edit the \"{$column->label}\" column.",
                ])->status(403);
            }
        }
    }

    /**
     * Ids of the columns on `$board_id` whose values `$user` may not see.
     *
     * @return array<int, int>
     */
    public function hiddenColumnIds(int $board_id, ?User $user): array
    {
        $key = $board_id.':'.($user?->id ?? 'guest');

        if (array_key_exists($key, $this->hidden_column_ids)) {
            return $this->hidden_column_ids[$key];
        }

        $restricted = BoardColumn::query()
            ->where('board_id', $board_id)
            ->whereNotNull('view_restriction')
            ->get(['id', 'board_id', 'view_restriction']);

        if ($restricted->isEmpty()) {
            return $this->hidden_column_ids[$key] = [];
        }

        $board = WorkspaceNavigationItem::query()->find($board_id);

        return $this->hidden_column_ids[$key] = $board === null
            ? []
            : $restricted->reject(fn (BoardColumn $column) => $this->canView($column, $user, $board))->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>|null  $restriction
     */
    private function passes(?array $restriction, ?User $user, WorkspaceNavigationItem $board): bool
    {
        if ($restriction === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->isOwner($board, $user)) {
            return true;
        }

        if (in_array($user->id, array_map('intval', $restriction['user_ids'] ?? []), true)) {
            return true;
        }

        $team_ids = array_map('intval', $restriction['team_ids'] ?? []);

        return $team_ids !== [] && array_intersect($team_ids, $this->teamIds($user)) !== [];
    }

    private function isOwner(WorkspaceNavigationItem $board, User $user): bool
    {
        $key = $board->id.':'.$user->id;

        return $this->owner_by_key[$key] ??= BoardEditGate::isOwner($board, $user);
    }

    /**
     * @return array<int, int>
     */
    private function teamIds(User $user): array
    {
        return $this->team_ids_by_user[$user->id] ??= DB::table('account_team_user')
            ->where('user_id', $user->id)
            ->pluck('account_team_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
