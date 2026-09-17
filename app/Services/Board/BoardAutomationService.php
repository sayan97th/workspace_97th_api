<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardItemController;
use App\Models\BoardActivityLog;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRun;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs the rule-based (no AI) automation recipes a board tab can define, see
 * {@see BoardAutomation}'s own doc comment for the trigger/action vocabulary:
 *
 * - "When `trigger_column_id` changes to `trigger_value`, run `action_type`" —
 *   {@see handleValueChanged()}, called synchronously from the same choke
 *   point every column-value write already goes through,
 *   {@see BoardItemController::syncValues()}. Covers `status_changed`,
 *   `person_assigned`.
 * - "When `trigger_column_id`'s date arrives, run `action_type`" —
 *   {@see runDueDateTriggers()}, called once daily by the scheduled
 *   `automations:run-date-triggers` command.
 * - "When an item/subitem is created, run `action_type`" —
 *   {@see handleItemCreated()}, called synchronously from
 *   {@see BoardItemController::store()}.
 */
class BoardAutomationService
{
    public function __construct(private readonly NotificationService $notification_service, private readonly BoardActivityLogger $activity_logger) {}

    /**
     * Reacts to one column's value having just changed on `$item` — a no-op
     * for every column that isn't a `status`/`label`/`people` kind, or that
     * no `status_changed`/`person_assigned` automation watches.
     */
    public function handleValueChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true)) {
            $this->handleStatusChanged($item, $column, $old_value, $new_value, $actor);

            return;
        }

        if ($column->type === BoardColumn::TYPE_PEOPLE) {
            $this->handlePersonAssigned($item, $column, $old_value, $new_value, $actor);
        }
    }

    /**
     * A single-select column's value is a plain option id string — comparing
     * the raw values is enough to tell whether this write actually changed
     * which option is selected, versus an unrelated re-save of the same one.
     */
    private function handleStatusChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($new_value === $old_value) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_STATUS_CHANGED)
            ->where('trigger_column_id', $column->id)
            ->get();

        foreach ($automations as $automation) {
            if ((string) $automation->trigger_value !== (string) $new_value) {
                continue;
            }

            $this->executeAction($automation, $item, $actor);
        }
    }

    /**
     * Fires once for every person newly added to a `people` column's value
     * (comparing against what was stored before this write) — a
     * `trigger_value` of null watches for anyone being assigned; a specific
     * user id only fires when that exact person is the one newly added.
     */
    private function handlePersonAssigned(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $old_ids = is_array($old_value) ? $old_value : [];
        $new_ids = is_array($new_value) ? $new_value : [];
        $newly_added_ids = array_diff($new_ids, $old_ids);

        if (empty($newly_added_ids)) {
            return;
        }

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_PERSON_ASSIGNED)
            ->where('trigger_column_id', $column->id)
            ->get();

        foreach ($automations as $automation) {
            $watched_user_id = $automation->trigger_value;
            $matches = $watched_user_id === null || in_array((string) $watched_user_id, array_map('strval', $newly_added_ids), true);

            if ($matches) {
                $this->executeAction($automation, $item, $actor);
            }
        }
    }

    /**
     * Reacts to `$item` having just been created — fires every enabled
     * `item_created` (root item) or `subitem_created` (has a parent)
     * automation on the item's tab.
     */
    public function handleItemCreated(BoardItem $item, ?User $actor): void
    {
        $trigger_type = $item->parent_id === null ? BoardAutomation::TRIGGER_ITEM_CREATED : BoardAutomation::TRIGGER_SUBITEM_CREATED;

        $automations = BoardAutomation::query()
            ->where('board_view_id', $item->group->board_view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', $trigger_type)
            ->get();

        foreach ($automations as $automation) {
            $this->executeAction($automation, $item, $actor);
        }
    }

    /**
     * Scans every enabled `date_arrived` automation for items whose trigger
     * column's stored date is today, runs the action once per (automation,
     * item) pair not already recorded in {@see BoardAutomationRun} for today.
     *
     * @return int How many (automation, item) pairs ran, for the calling command to report.
     */
    public function runDueDateTriggers(): int
    {
        $today = Carbon::today()->toDateString();
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_DATE_ARRIVED)
            ->get();

        foreach ($automations as $automation) {
            $already_ran_item_ids = BoardAutomationRun::where('automation_id', $automation->id)
                ->where('ran_on', $today)
                ->pluck('board_item_id');

            $due_values = BoardItemValue::where('column_id', $automation->trigger_column_id)
                ->whereRaw('JSON_UNQUOTE(`value`) = ?', [$today])
                ->whereNotIn('item_id', $already_ran_item_ids)
                ->with('item.group')
                ->get();

            foreach ($due_values as $value) {
                // `BoardItem` uses `SoftDeletes`, so its relation's default global
                // scope filters out a soft-deleted item, this can be null despite
                // `item_id`'s FK cascade (which only ever fires on a hard delete).
                $item = $value->item;
                if (! $item) {
                    continue;
                }

                $this->executeAction($automation, $item, null);

                BoardAutomationRun::create([
                    'automation_id' => $automation->id,
                    'board_item_id' => $item->id,
                    'ran_on' => $today,
                ]);
                $ran_count++;
            }
        }

        return $ran_count;
    }

    /**
     * Runs one automation's configured action against one item, then logs it
     * through {@see BoardActivityLogger} the same way every other board
     * mutation does.
     */
    private function executeAction(BoardAutomation $automation, BoardItem $item, ?User $actor): void
    {
        $automation_label = $automation->name ?: 'Automation';

        if ($automation->action_type === BoardAutomation::ACTION_MOVE_TO_GROUP) {
            $target_group_id = (int) ($automation->action_params['target_group_id'] ?? 0);
            if (! $target_group_id || $item->group_id === $target_group_id) {
                return;
            }

            DB::transaction(function () use ($item, $target_group_id) {
                $item->update(['group_id' => $target_group_id]);
                $this->cascadeGroupToDescendants($item, $target_group_id);
            });

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" moved \"{$item->name}\" to a different table",
                ['item_id' => $item->id]
            );

            return;
        }

        if ($automation->action_type === BoardAutomation::ACTION_NOTIFY_PERSON) {
            $recipient = $this->resolveNotifyTarget($automation, $item);
            if (! $recipient) {
                return;
            }

            $this->notification_service->notify(
                recipient: $recipient,
                actor: $actor,
                type: Notification::TYPE_AUTOMATION,
                board: $item->board,
                action_label: "Automation \"{$automation_label}\"",
                action_target: sprintf('ran on "%s" on the Board "%s"', $item->name, $item->board->label),
                link: "/boards/{$item->board_id}/pulses/{$item->id}",
                board_item: $item,
            );

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" notified {$recipient->full_name}",
                ['item_id' => $item->id]
            );

            return;
        }

        if ($automation->action_type === BoardAutomation::ACTION_ARCHIVE_ITEM) {
            $item->delete();

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" archived \"{$item->name}\"",
                ['item_id' => $item->id]
            );

            return;
        }

        if ($automation->action_type === BoardAutomation::ACTION_SET_COLUMN_VALUE) {
            $target_column_id = $automation->action_params['target_column_id'] ?? null;
            if (! $target_column_id) {
                return;
            }

            $item->values()->updateOrCreate(
                ['column_id' => $target_column_id],
                ['value' => $automation->action_params['value'] ?? null]
            );

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" updated a column on \"{$item->name}\"",
                ['item_id' => $item->id]
            );

            return;
        }

        if ($automation->action_type === BoardAutomation::ACTION_CREATE_ITEM) {
            $target_group_id = (int) ($automation->action_params['target_group_id'] ?? 0);
            if (! $target_group_id) {
                return;
            }

            $position = (int) BoardItem::where('group_id', $target_group_id)->whereNull('parent_id')->max('position') + 1;

            BoardItem::create([
                'board_id' => $item->board_id,
                'group_id' => $target_group_id,
                'parent_id' => null,
                'name' => $automation->action_params['item_name'] ?? 'New item',
                'position' => $position,
                'created_by_id' => $actor?->id,
            ]);

            $this->activity_logger->log(
                $item->board,
                $actor,
                BoardActivityLog::ACTION_AUTOMATION_RAN,
                "Automation \"{$automation_label}\" created a new item",
                ['item_id' => $item->id]
            );
        }
    }

    /**
     * A fixed `notify_user_id`, or whoever `notify_from_people_column_id`
     * currently holds (the first assigned person, when more than one).
     */
    private function resolveNotifyTarget(BoardAutomation $automation, BoardItem $item): ?User
    {
        if ($user_id = $automation->action_params['notify_user_id'] ?? null) {
            return User::find($user_id);
        }

        if ($people_column_id = $automation->action_params['notify_from_people_column_id'] ?? null) {
            $value = BoardItemValue::where('item_id', $item->id)->where('column_id', $people_column_id)->first();
            $person_ids = is_array($value?->value) ? $value->value : [];

            return $person_ids ? User::find($person_ids[0]) : null;
        }

        return null;
    }

    /**
     * Propagates a new `group_id` onto every descendant of `$item` — mirrors
     * {@see BoardItemController::cascadeGroupToDescendants()},
     * duplicated here (rather than reused) since that method is private on
     * the controller and this service has no other reason to depend on it.
     */
    private function cascadeGroupToDescendants(BoardItem $item, int $group_id): void
    {
        $item->loadMissing('childrenRecursive');

        $flatten = function (iterable $items) use (&$flatten): array {
            $flat = [];
            foreach ($items as $child) {
                $flat[] = $child;
                $flat = array_merge($flat, $flatten($child->childrenRecursive));
            }

            return $flat;
        };

        foreach ($flatten($item->childrenRecursive) as $descendant) {
            $descendant->update(['group_id' => $group_id]);
        }
    }
}
