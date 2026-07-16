<?php

namespace App\Services\Board;

use App\Models\BoardItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Server-side search for `GET /api/boards/{item}/items`. Kept intentionally
 * small: rich filtering/sorting/grouping stays client-side in
 * `useBoardToolbar` once items are loaded (see the plan's "filter execution
 * model" note) — this only narrows the row set for the `search` query param.
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
}
