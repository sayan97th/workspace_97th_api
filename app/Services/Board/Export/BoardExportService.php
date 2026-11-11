<?php

namespace App\Services\Board\Export;

use App\Jobs\ProcessBoardExportJob;
use App\Models\BoardColumn;
use App\Models\BoardExportJob;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardFormulaResolver;
use App\Services\Board\MirrorColumnResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File as FileSystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the "Export board to Excel" workbook for one {@see BoardExportJob},
 * run from {@see ProcessBoardExportJob} on the `exports` queue.
 *
 * Built to stay cheap however large the board is:
 * - Items are read group by group in chunks of {@see CHUNK_SIZE}, with keyset
 *   pagination on `(position, id)` rather than OFFSET, so the 200th chunk is
 *   as fast as the first and no full id list is ever held in memory.
 * - Everything a chunk refers to (people, tags, linked items, mirrored
 *   values) is loaded in a few batched queries, see {@see BoardExportLookups}.
 * - Rows are streamed to a temporary file by {@see BoardExportWorkbookWriter},
 *   then the finished file is streamed onto the storage disk.
 * - Between chunks the job reports progress and checks whether the person
 *   cancelled, so a long export can always be stopped.
 */
class BoardExportService
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly MirrorColumnResolver $mirror_resolver,
        private readonly BoardFormulaResolver $formula_resolver,
    ) {}

    /**
     * What the export dialog shows before anything runs: how many groups, items,
     * subitems and columns the chosen tab (or group) would export.
     *
     * @return array{groups: int, items: int, subitems: int, columns: int, subitem_columns: int, hidden_columns: int}
     */
    public function summary(WorkspaceNavigationItem $board, BoardView $view, ?BoardGroup $group): array
    {
        $group_ids = $this->groupsQuery($view, $group)->pluck('id');
        $item_query = fn () => BoardItem::query()->where('board_id', $board->id)->whereIn('group_id', $group_ids)->where('is_archived', false);

        $all_columns = $this->columns($view, BoardColumn::SCOPE_ITEM, true);
        $visible_columns = $this->columns($view, BoardColumn::SCOPE_ITEM, false);

        return [
            'groups' => $group_ids->count(),
            'items' => $item_query()->whereNull('parent_id')->count(),
            'subitems' => $item_query()->whereNotNull('parent_id')->whereIn('parent_id', $item_query()->whereNull('parent_id')->select('id'))->count(),
            'columns' => $all_columns->count(),
            'subitem_columns' => $this->columns($view, BoardColumn::SCOPE_SUBITEM, true)->count(),
            'hidden_columns' => $all_columns->count() - $visible_columns->count(),
        ];
    }

    /**
     * Writes the workbook and stores it on the export disk.
     *
     * @param  callable(int $processed, int $total): void  $onProgress
     * @param  callable(): bool  $isCancelled
     * @return array{cancelled: bool, disk: string|null, file_path: string|null, file_size_bytes: int, processed_rows: int, total_rows: int, column_count: int}
     */
    public function run(BoardExportJob $export_job, callable $onProgress, callable $isCancelled): array
    {
        $board = WorkspaceNavigationItem::findOrFail($export_job->board_id);
        $view = BoardView::findOrFail($export_job->board_view_id);
        $group = $export_job->group_id !== null ? BoardGroup::find($export_job->group_id) : null;
        $user = User::withTrashed()->find($export_job->user_id);

        $options = $export_job->options;
        $include_subitems = (bool) ($options['include_subitems'] ?? true);
        $include_hidden = (bool) ($options['include_hidden_columns'] ?? true);
        $time_zone = (string) ($options['time_zone'] ?? 'UTC');
        if (! BoardExportCellFormatter::isValidTimeZone($time_zone)) {
            $time_zone = 'UTC';
        }

        $item_columns = $this->columns($view, BoardColumn::SCOPE_ITEM, $include_hidden);
        $subitem_columns = $include_subitems ? $this->columns($view, BoardColumn::SCOPE_SUBITEM, $include_hidden) : collect();
        $groups = $this->groupsQuery($view, $group)->get();

        $context = new BoardExportRunContext(
            board: $board,
            view: $view,
            groups: $groups,
            item_columns: $item_columns,
            subitem_columns: $subitem_columns,
            all_item_columns: $view->columns()->where('scope', BoardColumn::SCOPE_ITEM)->get(),
            all_subitem_columns: $view->columns()->where('scope', BoardColumn::SCOPE_SUBITEM)->get(),
            include_subitems: $include_subitems,
            formatter: new BoardExportCellFormatter($lookups = new BoardExportLookups, $this->formula_resolver, $time_zone),
            lookups: $lookups,
            onProgress: $onProgress(...),
            isCancelled: $isCancelled(...),
        );

        $context->total_rows = $this->countRows($board, $groups, $include_subitems);
        $onProgress(0, $context->total_rows);

        $temp_path = $this->temporaryPath();
        $title = $group !== null ? "{$board->label} - {$group->name}" : (string) $board->label;
        $author = $user?->full_name ?: (string) config('app.name');
        $writer = new BoardExportWorkbookWriter($temp_path, $title, $author);

        try {
            $subtitle = sprintf(
                'Exported by %s on %s, %s view',
                $author,
                CarbonImmutable::now($time_zone)->format('M j, Y g:i A'),
                $view->label ?: 'Main table',
            );

            if ($export_job->layout() === BoardExportJob::LAYOUT_FLAT) {
                $this->writeFlat($writer, $context);
            } else {
                $this->writeGrouped($writer, $context, $title, $subtitle);
            }

            $writer->close();
        } catch (Throwable $exception) {
            FileSystem::delete($temp_path);
            throw $exception;
        }

        $column_count = 1 + $item_columns->count();

        if ($context->cancelled) {
            FileSystem::delete($temp_path);

            return ['cancelled' => true, 'disk' => null, 'file_path' => null, 'file_size_bytes' => 0, 'processed_rows' => $context->processed_rows, 'total_rows' => $context->total_rows, 'column_count' => $column_count];
        }

        $disk = (string) config('filesystems.default');
        $file_size_bytes = (int) filesize($temp_path);
        $stored_path = Storage::disk($disk)->putFileAs(
            "board-exports/{$board->id}",
            new File($temp_path),
            Str::uuid()->toString().'.xlsx',
            ['visibility' => 'private'],
        );
        FileSystem::delete($temp_path);

        return [
            'cancelled' => false,
            'disk' => $disk,
            'file_path' => $stored_path ?: null,
            'file_size_bytes' => $file_size_bytes,
            'processed_rows' => $context->processed_rows,
            'total_rows' => $context->total_rows,
            'column_count' => $column_count,
        ];
    }

    /**
     * The exported file's name: the board (and group) name, safe for every operating system.
     */
    public static function fileName(WorkspaceNavigationItem $board, ?BoardGroup $group): string
    {
        $label = $group !== null ? "{$board->label} {$group->name}" : (string) $board->label;
        $slug = Str::slug($label, '_') ?: 'board';

        return mb_substr($slug, 0, 80).'_'.now()->format('Y-m-d').'.xlsx';
    }

    // ── Layouts ─────────────────────────────────────────────────────────

    /** monday's own layout: a title, then each group with its own header row and its subitems tucked under each item. */
    private function writeGrouped(BoardExportWorkbookWriter $writer, BoardExportRunContext $context, string $title, string $subtitle): void
    {
        $widths = [0 => 44.0];
        foreach ($context->item_columns->values() as $index => $column) {
            $widths[$index + 1] = BoardExportCellFormatter::columnWidth($column);
        }
        foreach ($context->subitem_columns->values() as $index => $column) {
            $widths[$index + 2] = max($widths[$index + 2] ?? 0, BoardExportCellFormatter::columnWidth($column));
        }
        $widths[1] = max($widths[1] ?? 0, 36.0);

        $writer->startSheet($context->board->label ?: 'Board', $widths);
        $writer->writeTitle($title, $subtitle);

        $item_labels = ['Name', ...$context->item_columns->pluck('label')->map(fn ($label) => (string) $label)->all()];
        $subitem_labels = ['Subitem', ...$context->subitem_columns->pluck('label')->map(fn ($label) => (string) $label)->all()];

        foreach ($context->groups as $group) {
            if ($context->cancelled) {
                return;
            }

            $group_item_count = $this->rootItemsQuery($context->board, $group)->count();
            $writer->writeGroupTitle((string) $group->name, $group->accent_color, $group_item_count === 1 ? '1 item' : "{$group_item_count} items");
            $writer->writeHeaderRow($item_labels, 0, 0, $group->accent_color);

            $item_number = 0;
            $this->eachChunk($context, $group, function (EloquentCollection $items) use ($writer, $context, $group, $subitem_labels, &$item_number) {
                foreach ($items as $item) {
                    $item_number++;
                    $writer->writeItemRow($this->rowCells($context, $item, $context->item_columns, $context->all_item_columns, $item_number), 0, 0, $group->accent_color);

                    $children = $item->relationLoaded('children') ? $item->children : collect();
                    if ($context->include_subitems && $children->isNotEmpty()) {
                        $writer->writeHeaderRow($subitem_labels, 1, 1);
                        foreach ($children->values() as $child_index => $child) {
                            $writer->writeItemRow($this->rowCells($context, $child, $context->subitem_columns, $context->all_subitem_columns, $child_index + 1), 1, 1);
                        }
                    }
                }

                return $items->count() + $items->sum(fn (BoardItem $item) => $item->relationLoaded('children') ? $item->children->count() : 0);
            }, $context->include_subitems);

            $writer->writeSpacer();
        }
    }

    /** One header row with a Group column, frozen and filterable, subitems on their own sheet. */
    private function writeFlat(BoardExportWorkbookWriter $writer, BoardExportRunContext $context): void
    {
        $item_labels = ['Group', 'Name', ...$context->item_columns->pluck('label')->map(fn ($label) => (string) $label)->all()];
        $widths = [0 => 24.0, 1 => 44.0];
        foreach ($context->item_columns->values() as $index => $column) {
            $widths[$index + 2] = BoardExportCellFormatter::columnWidth($column);
        }

        $writeItemsHeader = function () use ($writer, $item_labels) {
            $writer->enableFilterOnNextHeader(count($item_labels) - 1);
            $writer->writeHeaderRow($item_labels);
        };

        $writer->startSheet('Items', $widths, 1, 'C', $writeItemsHeader);
        $writeItemsHeader();

        foreach ($context->groups as $group) {
            $item_number = 0;
            $this->eachChunk($context, $group, function (EloquentCollection $items) use ($writer, $context, $group, &$item_number) {
                foreach ($items as $item) {
                    $item_number++;
                    $writer->writeItemRow([
                        ExportCell::text((string) $group->name),
                        ...$this->rowCells($context, $item, $context->item_columns, $context->all_item_columns, $item_number),
                    ], 0, 0, null);
                }

                return $items->count();
            }, false);
        }

        if (! $context->include_subitems || $context->cancelled) {
            return;
        }

        // Subitems have their own column set, so they get their own sheet, written in a second pass.
        $subitem_labels = ['Group', 'Parent item', 'Subitem', ...$context->subitem_columns->pluck('label')->map(fn ($label) => (string) $label)->all()];
        $sub_widths = [0 => 24.0, 1 => 36.0, 2 => 40.0];
        foreach ($context->subitem_columns->values() as $index => $column) {
            $sub_widths[$index + 3] = BoardExportCellFormatter::columnWidth($column);
        }

        $writeSubitemsHeader = function () use ($writer, $subitem_labels) {
            $writer->enableFilterOnNextHeader(count($subitem_labels) - 1);
            $writer->writeHeaderRow($subitem_labels);
        };

        $writer->startSheet('Subitems', $sub_widths, 1, 'D', $writeSubitemsHeader);
        $writeSubitemsHeader();

        foreach ($context->groups as $group) {
            $this->eachChunk($context, $group, function (EloquentCollection $items) use ($writer, $context, $group) {
                $written = 0;
                foreach ($items as $item) {
                    foreach ($item->children->values() as $child_index => $child) {
                        $writer->writeItemRow([
                            ExportCell::text((string) $group->name),
                            ExportCell::text((string) $item->name),
                            ...$this->rowCells($context, $child, $context->subitem_columns, $context->all_subitem_columns, $child_index + 1),
                        ]);
                        $written++;
                    }
                }

                return $written;
            }, true, only_with_children: true);
        }
    }

    // ── Reading ─────────────────────────────────────────────────────────

    /**
     * Walks a group's root items in board order, chunk by chunk, with keyset pagination.
     * `$writeChunk` returns how many rows it wrote, for the progress bar.
     *
     * @param  callable(EloquentCollection<int, BoardItem>): int  $writeChunk
     */
    private function eachChunk(BoardExportRunContext $context, BoardGroup $group, callable $writeChunk, bool $with_children, bool $only_with_children = false): void
    {
        $last_position = null;
        $last_id = null;

        while (! $context->cancelled) {
            $query = $this->rootItemsQuery($context->board, $group)
                ->with(['values', 'latestActivity'])
                ->orderBy('position')
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE);

            if ($only_with_children) {
                $query->whereHas('children', fn (Builder $children) => $children->where('is_archived', false));
            }

            if ($last_position !== null) {
                $query->where(fn (Builder $after) => $after
                    ->where('position', '>', $last_position)
                    ->orWhere(fn (Builder $same) => $same->where('position', $last_position)->where('id', '>', $last_id)));
            }

            if ($with_children) {
                $query->with(['children' => fn (Relation $children) => $children
                    ->where('is_archived', false)
                    ->with(['values', 'latestActivity'])]);
            }

            $items = $query->get();
            if ($items->isEmpty()) {
                return;
            }

            $this->prepareChunk($context, $items, $with_children);

            $context->processed_rows += $writeChunk($items);
            ($context->onProgress)(min($context->processed_rows, $context->total_rows), $context->total_rows);

            if (($context->isCancelled)()) {
                $context->cancelled = true;

                return;
            }

            if ($items->count() < self::CHUNK_SIZE) {
                return;
            }

            $last = $items->last();
            $last_position = $last->position;
            $last_id = $last->id;
        }
    }

    /**
     * Loads everything the chunk's cells refer to before any row is written.
     *
     * @param  EloquentCollection<int, BoardItem>  $items
     */
    private function prepareChunk(BoardExportRunContext $context, EloquentCollection $items, bool $with_children): void
    {
        $this->mirror_resolver->attach($items, $context->all_item_columns);

        /** @var Collection<int, BoardItem> $children */
        $children = collect();
        if ($with_children) {
            foreach ($items as $item) {
                if ($item->relationLoaded('children')) {
                    $children = $children->concat($item->children);
                }
            }
        }

        if ($children->isNotEmpty()) {
            $this->mirror_resolver->attach($children, $context->all_subitem_columns);
        }

        $context->lookups->prime(
            $items->concat($children),
            $context->item_columns->concat($context->subitem_columns),
        );
    }

    /**
     * The name cell followed by one cell per exported column.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardColumn>  $scope_columns
     * @return array<int, ExportCell>
     */
    private function rowCells(BoardExportRunContext $context, BoardItem $item, Collection $columns, Collection $scope_columns, int $item_number): array
    {
        $cells = [ExportCell::text((string) $item->name)];
        foreach ($columns as $column) {
            $cells[] = $context->formatter->format($column, $item, $item_number, $scope_columns);
        }

        return $cells;
    }

    /**
     * @return Builder<BoardItem>
     */
    private function rootItemsQuery(WorkspaceNavigationItem $board, BoardGroup $group): Builder
    {
        return BoardItem::query()
            ->where('board_id', $board->id)
            ->where('group_id', $group->id)
            ->whereNull('parent_id')
            ->where('is_archived', false);
    }

    /**
     * @param  EloquentCollection<int, BoardGroup>  $groups
     */
    private function countRows(WorkspaceNavigationItem $board, EloquentCollection $groups, bool $include_subitems): int
    {
        $roots = fn () => BoardItem::query()
            ->where('board_id', $board->id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereNull('parent_id')
            ->where('is_archived', false);

        $total = $roots()->count();
        if ($include_subitems) {
            $total += BoardItem::query()->whereIn('parent_id', $roots()->select('id'))->where('is_archived', false)->count();
        }

        return $total;
    }

    /**
     * @return Builder<BoardGroup>
     */
    private function groupsQuery(BoardView $view, ?BoardGroup $group): Builder
    {
        return BoardGroup::query()
            ->where('board_view_id', $view->id)
            ->where('is_archived', false)
            ->when($group !== null, fn (Builder $query) => $query->where('id', $group->id))
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The exportable columns of one scope, in display order, optionally without the
     * columns hidden in this tab.
     *
     * @return Collection<int, BoardColumn>
     */
    private function columns(BoardView $view, string $scope, bool $include_hidden): Collection
    {
        $hidden_ids = collect($view->hidden_column_ids ?? [])->map(fn ($id) => (string) $id)->all();

        return $view->columns()
            ->where('scope', $scope)
            ->orderBy('position')
            ->get()
            ->filter(fn (BoardColumn $column) => BoardExportCellFormatter::isExportable($column))
            ->filter(fn (BoardColumn $column) => $include_hidden || ! in_array((string) $column->id, $hidden_ids, true))
            ->values();
    }

    private function temporaryPath(): string
    {
        $directory = storage_path('app/board-export-tmp');
        FileSystem::ensureDirectoryExists($directory);

        return $directory.'/'.Str::uuid()->toString().'.xlsx';
    }
}
