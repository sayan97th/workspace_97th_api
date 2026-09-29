<?php

namespace App\Services\Board;

use App\Models\BoardAutomationRunChange;
use App\Models\BoardItem;
use Throwable;

/**
 * Writes down every change the running automation makes inside the app, see
 * {@see BoardAutomationRunChange}, so {@see BoardAutomationRunUndoer} can take the run back later.
 * Outside a run, and during a test run, it records nothing.
 *
 * Recording is best effort: a failure to write the journal is reported and never breaks the
 * automation that made the change.
 */
class BoardAutomationRunJournal
{
    public function __construct(private readonly AutomationRunContext $run_context) {}

    public function valueChanged(BoardItem $item, int $column_id, mixed $before, mixed $after): void
    {
        $this->record(BoardAutomationRunChange::KIND_VALUE, $item, $column_id, ['value' => $before], ['value' => $after]);
    }

    public function moved(BoardItem $item, int $from_group_id, int $to_group_id): void
    {
        $this->record(BoardAutomationRunChange::KIND_MOVED, $item, null, ['group_id' => $from_group_id], ['group_id' => $to_group_id]);
    }

    public function archived(BoardItem $item): void
    {
        $this->record(BoardAutomationRunChange::KIND_ARCHIVED, $item, null, null, null);
    }

    /**
     * @param  array<int, int>  $descendant_ids  the subitems deleted together with the item
     */
    public function deleted(BoardItem $item, array $descendant_ids): void
    {
        $this->record(BoardAutomationRunChange::KIND_DELETED, $item, null, null, ['descendant_ids' => array_values($descendant_ids)]);
    }

    public function renamed(BoardItem $item, string $from, string $to): void
    {
        $this->record(BoardAutomationRunChange::KIND_RENAMED, $item, null, ['name' => $from], ['name' => $to]);
    }

    public function created(BoardItem $item): void
    {
        $this->record(BoardAutomationRunChange::KIND_CREATED, $item, null, null, null);
    }

    /**
     * Items put in a new order, the top level items of a group or the subitems of an item.
     *
     * @param  int|null  $item_id  the item the automation ran on, null for a run without one
     * @param  array<int, int>  $before  the ids in their order before the run
     * @param  array<int, int>  $after  the same ids in the order the run left them
     */
    public function reordered(int $board_id, ?int $item_id, array $before, array $after): void
    {
        $this->write(BoardAutomationRunChange::KIND_REORDERED, $board_id, $item_id, null, ['order' => array_values($before)], ['order' => array_values($after)]);
    }

    /**
     * Whether a change made right now would be recorded.
     */
    public function isRecording(): bool
    {
        return $this->run_context->currentRunUuid() !== null && ! $this->run_context->isDryRun();
    }

    private function record(string $kind, BoardItem $item, ?int $column_id, mixed $before, mixed $after): void
    {
        $this->write($kind, $item->board_id, $item->id, $column_id, $before, $after);
    }

    private function write(string $kind, int $board_id, ?int $item_id, ?int $column_id, mixed $before, mixed $after): void
    {
        $run_uuid = $this->run_context->currentRunUuid();
        if ($run_uuid === null || $this->run_context->isDryRun()) {
            return;
        }

        try {
            BoardAutomationRunChange::create([
                'run_uuid' => $run_uuid,
                'automation_id' => $this->run_context->current()?->exists ? $this->run_context->current()->id : null,
                'board_id' => $board_id,
                'board_item_id' => $item_id,
                'kind' => $kind,
                'column_id' => $column_id,
                'before' => $before,
                'after' => $after,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
