<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemRecurrence;
use App\Models\BoardItemValue;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Carbon;

/**
 * Scans every enabled {@see BoardItemRecurrence} whose `next_run_date` has
 * arrived, recreates that item (and its whole subitem subtree), and advances
 * `next_run_date` by the recurrence's frequency/interval. Mirrors
 * {@see BoardAutomationService::runDueDateTriggers()}'s daily scheduled-scan
 * shape, but has nothing to dedupe against (`next_run_date` only ever
 * matches "due" for one calendar day before this bumps it forward).
 */
class RecurringItemService
{
    public function __construct(private readonly BoardActivityLogger $activity_logger) {}

    public function run(): int
    {
        $today = Carbon::today()->toDateString();
        $recurred_count = 0;

        $due_recurrences = BoardItemRecurrence::where('is_enabled', true)
            ->where('next_run_date', '<=', $today)
            ->with(['item.board', 'item.values', 'item.childrenRecursive', 'item.group'])
            ->get();

        foreach ($due_recurrences as $recurrence) {
            // `BoardItem` uses `SoftDeletes`, so this can be null despite the
            // FK cascade, which only ever fires on a hard delete.
            $original = $recurrence->item;
            if (! $original) {
                continue;
            }

            $board = $original->board;
            $position = (int) BoardItem::where('board_id', $board->id)
                ->where('group_id', $original->group_id)
                ->whereNull('parent_id')
                ->max('position') + 1;

            $copy = $this->copySubtree($board, $original, $original->group_id, null, $position);

            $this->activity_logger->log(
                $board,
                null,
                BoardActivityLog::ACTION_ITEM_RECURRED,
                "Recreated \"{$original->name}\" from its recurring schedule",
                ['item_id' => $copy->id, 'source_item_id' => $original->id]
            );

            $recurrence->update(['next_run_date' => $this->nextRunDate($recurrence)]);

            $recurred_count++;
        }

        return $recurred_count;
    }

    private function nextRunDate(BoardItemRecurrence $recurrence): string
    {
        $base = Carbon::parse((string) $recurrence->next_run_date);

        return match ($recurrence->frequency) {
            BoardItemRecurrence::FREQUENCY_WEEKLY => $base->addWeeks($recurrence->interval_count)->toDateString(),
            BoardItemRecurrence::FREQUENCY_MONTHLY => $base->addMonthsNoOverflow($recurrence->interval_count)->toDateString(),
            default => $base->addDays($recurrence->interval_count)->toDateString(),
        };
    }

    /**
     * Duplicated from {@see \App\Http\Controllers\Board\BoardItemController::copySubtree()}
     * (private there) rather than reused — same reasoning as
     * {@see BoardAutomationService::cascadeGroupToDescendants()}'s own
     * duplicated copy: no other reason for this service to depend on the
     * controller. Recreates with the item's own name (no "(copy)" suffix),
     * since a recurring task reproducing itself isn't a duplicate.
     */
    private function copySubtree(WorkspaceNavigationItem $board, BoardItem $original, int $group_id, ?int $parent_id, int $position): BoardItem
    {
        $copy = $board->items()->create([
            'group_id' => $group_id,
            'parent_id' => $parent_id,
            'name' => $original->name,
            'description' => $original->description,
            'position' => $position,
            'created_by_id' => $original->created_by_id,
        ]);

        $scope = $parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        // An Auto-number column's value is a stable, per-item identity, not
        // board content — the recreated item gets its own freshly-assigned
        // number below instead of literally cloning the original's.
        $auto_number_column_ids = BoardColumn::where('board_view_id', $original->group->board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->pluck('id');

        foreach ($original->values as $value) {
            if ($auto_number_column_ids->contains($value->column_id)) {
                continue;
            }
            $copy->values()->create([
                'column_id' => $value->column_id,
                'value' => $value->value,
            ]);
        }

        $this->assignAutoNumberValues($copy, $scope);

        foreach ($original->childrenRecursive as $child) {
            $this->copySubtree($board, $child, $group_id, $copy->id, $child->position);
        }

        return $copy;
    }

    private function assignAutoNumberValues(BoardItem $board_item, string $scope): void
    {
        $auto_number_columns = BoardColumn::where('board_view_id', $board_item->group->board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->get(['id']);

        foreach ($auto_number_columns as $column) {
            $next = BoardItemValue::where('column_id', $column->id)
                ->pluck('value')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : 0)
                ->max() ?? 0;

            $board_item->values()->create(['column_id' => $column->id, 'value' => $next + 1]);
        }
    }
}
