<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use Illuminate\Support\Facades\DB;

/**
 * Moves a top-level item, together with its whole subitem subtree, from one
 * board into a table (group) of another board, the item drawer's "Move to
 * board" action.
 *
 * Comments, replies, attachments, checklist items and recurrence are keyed by
 * `item_id`, so they follow the item untouched. Column values are the only
 * thing that cannot: columns belong to a single board tab, so each value is
 * carried across by matching the source column to a target column with the
 * same label and type (see {@see buildColumnMap()}). A value with no matching
 * column, or one whose meaning is tied to the source board, is dropped
 * instead of being left pointing at a column of the wrong board.
 */
class BoardItemTransferService
{
    /**
     * Column types whose value is only meaningful on the board it was written
     * on, so it is never carried across: tags reference a board-wide tag
     * list, dependency and connect-board reference other items of the source
     * board, and formula, mirror and auto-number are derived or assigned per
     * board.
     */
    private const NON_TRANSFERABLE_TYPES = [
        BoardColumn::TYPE_TAGS,
        BoardColumn::TYPE_DEPENDENCY,
        BoardColumn::TYPE_CONNECT_BOARD,
        BoardColumn::TYPE_FORMULA,
        BoardColumn::TYPE_MIRROR,
        BoardColumn::TYPE_AUTO_NUMBER,
    ];

    /** Column types whose value is an option id (or a list of them) resolved against `config.options`. */
    private const OPTION_TYPES = [
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_DROPDOWN,
        BoardColumn::TYPE_LABEL,
    ];

    /**
     * Moves `$board_item` and every descendant into `$target_group`, appended
     * at the end of that table. All writes happen in one transaction so a
     * failure part way never leaves the subtree split across two boards.
     */
    public function moveToBoard(BoardItem $board_item, BoardGroup $target_group): BoardItem
    {
        return DB::transaction(function () use ($board_item, $target_group) {
            $board_item->load(['group', 'values', 'childrenRecursive']);

            $target_board_id = $target_group->board_id;
            $target_view_id = $target_group->board_view_id;
            $source_view_id = $board_item->group->board_view_id;

            $column_maps = [
                BoardColumn::SCOPE_ITEM => $this->buildColumnMap($source_view_id, $target_view_id, BoardColumn::SCOPE_ITEM),
                BoardColumn::SCOPE_SUBITEM => $this->buildColumnMap($source_view_id, $target_view_id, BoardColumn::SCOPE_SUBITEM),
            ];

            $position = (int) BoardItem::where('board_id', $target_board_id)
                ->where('group_id', $target_group->id)
                ->whereNull('parent_id')
                ->max('position') + 1;

            $board_item->update([
                'board_id' => $target_board_id,
                'group_id' => $target_group->id,
                'position' => $position,
            ]);
            $this->transferValues($board_item, $column_maps[BoardColumn::SCOPE_ITEM]);
            $this->assignAutoNumberValues($board_item, $target_view_id, BoardColumn::SCOPE_ITEM);

            foreach ($this->flattenTree($board_item->childrenRecursive) as $descendant) {
                $descendant->update(['board_id' => $target_board_id, 'group_id' => $target_group->id]);
                $this->transferValues($descendant, $column_maps[BoardColumn::SCOPE_SUBITEM]);
                $this->assignAutoNumberValues($descendant, $target_view_id, BoardColumn::SCOPE_SUBITEM);
            }

            return $board_item->fresh(['values', 'childrenRecursive']);
        });
    }

    /**
     * Maps every source column id in `$scope` to the target tab's column of
     * the same type and (case-insensitive) label. Each target column is used
     * at most once, so two same-named source columns never collide on one
     * target column.
     *
     * @return array<int, array{source: BoardColumn, target: BoardColumn}> keyed by source column id
     */
    private function buildColumnMap(int $source_view_id, int $target_view_id, string $scope): array
    {
        $target_columns = BoardColumn::where('board_view_id', $target_view_id)->where('scope', $scope)->orderBy('position')->get();
        $source_columns = BoardColumn::where('board_view_id', $source_view_id)->where('scope', $scope)->orderBy('position')->get();

        $claimed_target_ids = [];
        $map = [];

        foreach ($source_columns as $source_column) {
            if (in_array($source_column->type, self::NON_TRANSFERABLE_TYPES, true)) {
                continue;
            }

            $match = $target_columns->first(
                fn (BoardColumn $target) => ! in_array($target->id, $claimed_target_ids, true)
                    && $target->type === $source_column->type
                    && mb_strtolower($target->label) === mb_strtolower($source_column->label)
            );

            if ($match) {
                $claimed_target_ids[] = $match->id;
                $map[$source_column->id] = ['source' => $source_column, 'target' => $match];
            }
        }

        return $map;
    }

    /**
     * Re-points each of `$item`'s values at its mapped target column and
     * deletes the ones with no counterpart on the target board.
     *
     * @param  array<int, array{source: BoardColumn, target: BoardColumn}>  $column_map
     */
    private function transferValues(BoardItem $item, array $column_map): void
    {
        $item->loadMissing('values');

        foreach ($item->values as $value) {
            $mapping = $column_map[$value->column_id] ?? null;
            $carried_value = $mapping ? $this->carryValue($mapping['source'], $mapping['target'], $value->value) : null;

            if ($carried_value === null) {
                $value->delete();

                continue;
            }

            $value->update(['column_id' => $mapping['target']->id, 'value' => $carried_value]);
        }
    }

    /**
     * The value as it should read on the target column, or null when it has
     * no valid equivalent there. Option columns resolve each option id to the
     * target column's option with the same label.
     */
    private function carryValue(BoardColumn $source, BoardColumn $target, mixed $value): mixed
    {
        if (! in_array($source->type, self::OPTION_TYPES, true)) {
            return $value;
        }

        $source_options = collect($source->config['options'] ?? [])->keyBy('id');
        $target_options_by_label = collect($target->config['options'] ?? [])
            ->keyBy(fn (array $option) => mb_strtolower($option['label'] ?? ''));

        $resolve = function ($option_id) use ($source_options, $target_options_by_label) {
            $label = mb_strtolower($source_options->get($option_id)['label'] ?? '');

            return $target_options_by_label->get($label)['id'] ?? null;
        };

        if (is_array($value)) {
            $resolved = collect($value)->map($resolve)->filter()->values()->all();

            return $resolved === [] ? null : $resolved;
        }

        return $resolve($value);
    }

    /**
     * Gives `$item` its next sequential number in every auto-number column of
     * the target tab, counted per column, an auto-number value is a stable
     * per-board identity, so it is assigned fresh rather than carried across.
     * Mirrors `BoardItemController::assignAutoNumberValues()`.
     */
    private function assignAutoNumberValues(BoardItem $item, int $target_view_id, string $scope): void
    {
        $auto_number_columns = BoardColumn::where('board_view_id', $target_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->get(['id']);

        foreach ($auto_number_columns as $column) {
            $next = BoardItemValue::where('column_id', $column->id)
                ->pluck('value')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : 0)
                ->max() ?? 0;

            $item->values()->create(['column_id' => $column->id, 'value' => $next + 1]);
        }
    }

    /**
     * Flattens a nested collection of items into a single list.
     *
     * @param  iterable<BoardItem>  $items
     * @return array<int, BoardItem>
     */
    private function flattenTree(iterable $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;
            $flat = array_merge($flat, $this->flattenTree($item->childrenRecursive));
        }

        return $flat;
    }
}
