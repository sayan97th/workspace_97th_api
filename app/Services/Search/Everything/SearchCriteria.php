<?php

namespace App\Services\Search\Everything;

use App\Models\BoardItem;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything one Search Everything request asks for, shared by every tab: who is searching,
 * the term, the sort, the "Filter by date" range, "Search archives" and the facet checkboxes of
 * the Updates and Files tabs.
 */
final class SearchCriteria
{
    public const SORT_RELEVANCE = 'relevance';

    public const SORT_TIME = 'time';

    /**
     * @param  array<int, string>  $filters  Updates facets, see {@see UpdatesTab::FILTERS}.
     * @param  array<int, string>  $file_types  Files facets, see {@see FileKind::ALL}.
     */
    public function __construct(
        public readonly User $user,
        public readonly string $term,
        public readonly string $sort = self::SORT_RELEVANCE,
        public readonly ?CarbonImmutable $date_from = null,
        public readonly ?CarbonImmutable $date_to = null,
        public readonly bool $include_archived = false,
        public readonly array $filters = [],
        public readonly array $file_types = [],
    ) {}

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    public function sortsByTime(): bool
    {
        return $this->sort === self::SORT_TIME;
    }

    public function hasFilter(string $filter): bool
    {
        return in_array($filter, $this->filters, true);
    }

    /**
     * Ids of the boards the user can open, archived ones too with "Search archives".
     *
     * @return Builder<WorkspaceNavigationItem>
     */
    public function visibleBoardIds(): Builder
    {
        return VisibleBoards::query($this->user, $this->include_archived)->select('id');
    }

    /**
     * Ids of the items on visible boards. By default only live items (not archived, in a group
     * that is not archived), with "Search archives" every item, and with `$archived_only` just
     * the archived ones, for the Updates tab's "Archived" checkbox.
     *
     * @return Builder<BoardItem>
     */
    public function itemIds(bool $archived_only = false): Builder
    {
        $query = BoardItem::query()
            ->whereIn('board_id', VisibleBoards::query($this->user, $this->include_archived || $archived_only)->select('id'));

        if ($archived_only) {
            return $query
                ->where(fn (Builder $archived) => $archived->where('is_archived', true)
                    ->orWhereHas('group', fn (Builder $group) => $group->where('is_archived', true)))
                ->select('id');
        }

        return $query
            ->unless($this->include_archived, fn (Builder $live) => $live
                ->where('is_archived', false)
                ->whereDoesntHave('group', fn (Builder $group) => $group->where('is_archived', true)))
            ->select('id');
    }

    /**
     * Keeps rows whose `$column` falls inside the "Filter by date" range (whole days).
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyDateRange(BuilderContract $query, string $column): BuilderContract
    {
        return $query
            ->when($this->date_from, fn ($inner) => $inner->where($column, '>=', $this->date_from->startOfDay()))
            ->when($this->date_to, fn ($inner) => $inner->where($column, '<=', $this->date_to->endOfDay()));
    }

    /**
     * Whether a timestamp falls inside the "Filter by date" range, for rows filtered in PHP.
     */
    public function isInDateRange(?CarbonImmutable $moment): bool
    {
        if ($moment === null) {
            return $this->date_from === null && $this->date_to === null;
        }

        return ($this->date_from === null || $moment->gte($this->date_from->startOfDay()))
            && ($this->date_to === null || $moment->lte($this->date_to->endOfDay()));
    }
}
