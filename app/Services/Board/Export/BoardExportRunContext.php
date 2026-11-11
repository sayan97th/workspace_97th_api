<?php

namespace App\Services\Board\Export;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The state of one export run, shared by {@see BoardExportService}'s layout
 * writers: what is exported, the formatter, and how far along it is.
 */
final class BoardExportRunContext
{
    public int $total_rows = 0;

    public int $processed_rows = 0;

    public bool $cancelled = false;

    /**
     * @param  EloquentCollection<int, BoardGroup>  $groups
     * @param  Collection<int, BoardColumn>  $item_columns  exported item columns, in order
     * @param  Collection<int, BoardColumn>  $subitem_columns  exported subitem columns, in order
     * @param  Collection<int, BoardColumn>  $all_item_columns  every item column, for Mirror and Progress tracking
     * @param  Collection<int, BoardColumn>  $all_subitem_columns  every subitem column
     * @param  Closure(int, int): void  $onProgress
     * @param  Closure(): bool  $isCancelled
     */
    public function __construct(
        public readonly WorkspaceNavigationItem $board,
        public readonly BoardView $view,
        public readonly EloquentCollection $groups,
        public readonly Collection $item_columns,
        public readonly Collection $subitem_columns,
        public readonly Collection $all_item_columns,
        public readonly Collection $all_subitem_columns,
        public readonly bool $include_subitems,
        public readonly BoardExportCellFormatter $formatter,
        public readonly BoardExportLookups $lookups,
        public readonly Closure $onProgress,
        public readonly Closure $isCancelled,
    ) {}
}
