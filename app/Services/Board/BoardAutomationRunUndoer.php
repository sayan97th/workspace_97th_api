<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\BoardAutomationDelayedRun;
use App\Models\BoardAutomationRunChange;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Undo" on a run of the run history: takes back, newest first, every change the run made inside
 * the app ({@see BoardAutomationRunChange}). A change that someone (or something) changed again
 * since is left alone and reported, so an undo never overwrites newer work. Emails, Slack messages,
 * notifications and webhooks already left the app and cannot be taken back.
 *
 * The undo writes directly instead of through {@see BoardItemValueService::sync()}, so taking a
 * run back never sets other automations off. Every reverted cell still gets its item activity
 * entry, credited to the person who undid the run.
 */
class BoardAutomationRunUndoer
{
    public function __construct(
        private readonly BoardItemActivityService $activity_service,
        private readonly BoardActivityLogger $activity_logger,
        private readonly BoardAutomationService $automation_service,
    ) {}

    /**
     * Which of `$run_uuids` still have changes that can be undone.
     *
     * @param  array<int, string>  $run_uuids
     * @return array<int, string>
     */
    public static function undoableRunUuids(array $run_uuids): array
    {
        $run_uuids = array_values(array_unique(array_filter($run_uuids)));
        if ($run_uuids === []) {
            return [];
        }

        return BoardAutomationRunChange::whereIn('run_uuid', $run_uuids)
            ->whereNull('undone_at')
            ->where('created_at', '>=', now()->subDays(BoardAutomationRunChange::UNDO_WINDOW_DAYS))
            ->distinct()
            ->pluck('run_uuid')
            ->all();
    }

    /**
     * @return array{reverted: int, skipped: array<int, string>}
     */
    public function undo(string $run_uuid, ?User $actor): array
    {
        $reverted = 0;
        $skipped = [];

        DB::transaction(function () use ($run_uuid, $actor, &$reverted, &$skipped) {
            $changes = BoardAutomationRunChange::where('run_uuid', $run_uuid)->whereNull('undone_at')->orderByDesc('id')->lockForUpdate()->get();

            foreach ($changes as $change) {
                $problem = $this->revert($change, $actor);
                if ($problem === null) {
                    $reverted++;
                } else {
                    $skipped[] = $problem;
                }
                $change->forceFill(['undone_at' => now()])->save();
            }

            BoardAutomationRunLog::where('run_uuid', $run_uuid)->update(['undone_at' => now(), 'undone_by_id' => $actor?->id]);
            BoardAutomationDelayedRun::where('run_uuid', $run_uuid)
                ->where('status', BoardAutomationDelayedRun::STATUS_PENDING)
                ->update(['status' => BoardAutomationDelayedRun::STATUS_CANCELLED]);
        });

        return ['reverted' => $reverted, 'skipped' => array_values(array_unique($skipped))];
    }

    /**
     * Takes one change back, or says why it was left alone.
     */
    private function revert(BoardAutomationRunChange $change, ?User $actor): ?string
    {
        if ($change->kind === BoardAutomationRunChange::KIND_REORDERED) {
            return $this->revertReorder($change, $actor);
        }

        $item = BoardItem::withTrashed()->with('group')->find($change->board_item_id);
        if (! $item) {
            return 'An item the run changed was permanently deleted.';
        }

        return match ($change->kind) {
            BoardAutomationRunChange::KIND_VALUE => $this->revertValue($change, $item, $actor),
            BoardAutomationRunChange::KIND_MOVED => $this->revertMove($change, $item, $actor),
            BoardAutomationRunChange::KIND_ARCHIVED => $this->revertArchive($item, $actor),
            BoardAutomationRunChange::KIND_DELETED => $this->revertDelete($change, $item, $actor),
            BoardAutomationRunChange::KIND_RENAMED => $this->revertRename($change, $item, $actor),
            BoardAutomationRunChange::KIND_CREATED => $this->revertCreate($item, $actor),
            default => 'A change of an unknown kind was skipped.',
        };
    }

    private function revertValue(BoardAutomationRunChange $change, BoardItem $item, ?User $actor): ?string
    {
        $column = BoardColumn::find($change->column_id);
        if (! $column) {
            return 'A column the run changed was deleted.';
        }
        if ($item->trashed()) {
            return "\"{$item->name}\" was deleted, so \"{$column->label}\" was left as it is.";
        }

        $current = BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;
        $after = $change->after['value'] ?? null;
        $before = $change->before['value'] ?? null;
        if (! $this->automation_service->valuesAreEqual($current, $after)) {
            return "\"{$column->label}\" on \"{$item->name}\" changed again after the run, so it was left as it is.";
        }

        $item->values()->updateOrCreate(['column_id' => $column->id], ['value' => $before]);
        $this->activity_service->record($item, $column, $current, $before, $actor);

        return null;
    }

    private function revertMove(BoardAutomationRunChange $change, BoardItem $item, ?User $actor): ?string
    {
        $from_group_id = (int) ($change->before['group_id'] ?? 0);
        $to_group_id = (int) ($change->after['group_id'] ?? 0);
        if ($item->trashed() || $item->group_id !== $to_group_id) {
            return "\"{$item->name}\" moved again after the run, so it stayed where it is.";
        }

        $group = BoardGroup::where('board_id', $item->board_id)->where('is_archived', false)->find($from_group_id);
        if (! $group) {
            return "The group \"{$item->name}\" came from no longer exists.";
        }

        $position = (int) BoardItem::where('group_id', $group->id)->whereNull('parent_id')->max('position') + 1;
        $item->update(['group_id' => $group->id, 'position' => $position]);
        BoardItem::where('parent_id', $item->id)->update(['group_id' => $group->id]);
        $this->log($item, $actor, "moved \"{$item->name}\" back to \"{$group->name}\"");

        return null;
    }

    private function revertArchive(BoardItem $item, ?User $actor): ?string
    {
        if ($item->trashed() || ! $item->is_archived) {
            return "\"{$item->name}\" was restored or deleted after the run.";
        }

        $item->update(['is_archived' => false]);
        $this->log($item, $actor, "restored \"{$item->name}\" from the archive");

        return null;
    }

    private function revertDelete(BoardAutomationRunChange $change, BoardItem $item, ?User $actor): ?string
    {
        if (! $item->trashed()) {
            return "\"{$item->name}\" was already restored.";
        }

        $item->restore();
        $descendant_ids = array_map('intval', (array) ($change->after['descendant_ids'] ?? []));
        if ($descendant_ids !== []) {
            BoardItem::onlyTrashed()->whereIn('id', $descendant_ids)->restore();
        }
        $this->log($item, $actor, "restored the deleted item \"{$item->name}\"");

        return null;
    }

    private function revertRename(BoardAutomationRunChange $change, BoardItem $item, ?User $actor): ?string
    {
        $from = (string) ($change->before['name'] ?? '');
        $to = (string) ($change->after['name'] ?? '');
        if ($item->trashed() || trim((string) $item->name) !== trim($to)) {
            return "\"{$item->name}\" was renamed again after the run, so its name was kept.";
        }

        $item->update(['name' => $from]);
        $this->log($item, $actor, "renamed \"{$to}\" back to \"{$from}\"");

        return null;
    }

    /**
     * Puts reordered items back in their order from before the run. Items moved elsewhere or
     * deleted since are left out, the others take back their places among the slots they hold
     * now, so items added since keep theirs. Nothing moves when they were reordered again.
     */
    private function revertReorder(BoardAutomationRunChange $change, ?User $actor): ?string
    {
        $before = array_map('intval', (array) ($change->before['order'] ?? []));
        $after = array_map('intval', (array) ($change->after['order'] ?? []));
        $first = BoardItem::whereIn('id', $before)->orderBy('position')->first();
        if (! $first) {
            return 'The items the run reordered no longer exist.';
        }

        $siblings = ($first->parent_id === null
            ? BoardItem::where('group_id', $first->group_id)->whereNull('parent_id')
            : BoardItem::where('parent_id', $first->parent_id))
            ->where('is_archived', false)
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $kept = array_values(array_intersect($siblings, $before));
        if ($kept !== array_values(array_intersect($after, $kept))) {
            return 'The items were reordered again after the run, so their order stayed as it is.';
        }

        $restored = array_values(array_intersect($before, $kept));
        $next = $siblings;
        $slot = 0;
        foreach ($next as $position => $id) {
            if (in_array($id, $kept, true)) {
                $next[$position] = $restored[$slot++];
            }
        }
        foreach ($next as $position => $id) {
            BoardItem::whereKey($id)->update(['position' => $position]);
        }
        $this->log($first, $actor, 'put '.count($restored).' items back in their previous order');

        return null;
    }

    private function revertCreate(BoardItem $item, ?User $actor): ?string
    {
        if ($item->trashed()) {
            return "\"{$item->name}\" was already deleted.";
        }

        $item->loadMissing('childrenRecursive');
        $pending = [...$item->childrenRecursive];
        while ($pending !== []) {
            $child = array_shift($pending);
            array_push($pending, ...$child->childrenRecursive);
            $child->delete();
        }
        $item->delete();
        $this->log($item, $actor, "deleted \"{$item->name}\", which the automation had created");

        return null;
    }

    private function log(BoardItem $item, ?User $actor, string $what): void
    {
        $board = $item->board;
        if (! $board) {
            return;
        }

        $this->activity_logger->log($board, $actor, BoardActivityLog::ACTION_AUTOMATION_RAN, "Undo of an automation run {$what}", ['item_id' => $item->id]);
    }
}
