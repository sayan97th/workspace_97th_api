<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Notification\NotificationService;

/**
 * Writes cell values for a board item. Shared by the inline cell edit, the
 * bulk "Edit column" action and the comment composer's "Assign" action so all
 * of them get the same side effects: the "Assigned you" notification, board
 * automations and the item activity entry the Update Feed shows.
 */
class BoardItemValueService
{
    public function __construct(
        private readonly NotificationService $notification_service,
        private readonly BoardAutomationService $automation_service,
        private readonly BoardItemActivityService $activity_service,
    ) {}

    /**
     * Upserts one {@link BoardItemValue} per entry in `$values`, silently
     * skipping any column id that doesn't belong to this item's own tab
     * (columns are per-tab, so a column from a different tab of the same
     * board is rejected too, not just columns from other boards).
     *
     * When `$actor` is given, newly-added people on a people-type column
     * trigger an "Assigned you" notification, see {@see notifyNewlyAssignedPeople()}.
     * `$record_activity` is switched off while an item is being created, since
     * its starting values are not changes.
     *
     * @param  array<string, mixed>  $values
     */
    public function sync(WorkspaceNavigationItem $item, BoardItem $board_item, array $values, ?User $actor = null, bool $record_activity = true): void
    {
        // A subitem may only be assigned values for subitem-scoped columns,
        // and a root item only for item-scoped ones, the two column sets are
        // independent, mirroring how monday.com's subitems carry their own
        // separate columns rather than reusing the parent item's.
        $scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        $valid_columns = BoardColumn::where('board_view_id', $board_item->group->board_view_id)
            ->where('scope', $scope)
            ->whereIn('id', array_map('intval', array_keys($values)))
            ->get(['id', 'type', 'config', 'label'])
            ->keyBy('id');

        foreach ($values as $column_id => $value) {
            $column = $valid_columns->get((int) $column_id);
            if (! $column) {
                continue;
            }

            if ($actor && $column->type === BoardColumn::TYPE_PEOPLE) {
                $this->notifyNewlyAssignedPeople($item, $board_item, $column, $value, $actor);
            }

            $old_value = BoardItemValue::where('item_id', $board_item->id)->where('column_id', $column->id)->first()?->value;

            $board_item->values()->updateOrCreate(
                ['column_id' => $column->id],
                ['value' => $value]
            );

            if ($record_activity) {
                $this->activity_service->record($board_item, $column, $old_value, $value, $actor);
            }

            $this->automation_service->handleValueChanged($board_item, $column, $old_value, $value, $actor);
        }
    }

    /**
     * Assigns every Auto-number column in this item's scope its next
     * sequential value (1, 2, 3, ...), scoped to that column alone, so a
     * board's second Auto-number column (if it ever added one) counts
     * independently from the first. No-ops for a column the item already
     * has a value for, so this is safe to call unconditionally from
     * item creation (a client-supplied value never wins a race with this),
     * duplication (after deliberately stripping the original's own
     * auto-number value) and form submissions.
     */
    public function assignAutoNumbers(BoardItem $board_item, string $scope): void
    {
        $auto_number_columns = BoardColumn::where('board_view_id', $board_item->group->board_view_id)
            ->where('scope', $scope)
            ->where('type', BoardColumn::TYPE_AUTO_NUMBER)
            ->get(['id']);

        foreach ($auto_number_columns as $column) {
            if (BoardItemValue::where('item_id', $board_item->id)->where('column_id', $column->id)->exists()) {
                continue;
            }

            $next = BoardItemValue::where('column_id', $column->id)
                ->pluck('value')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : 0)
                ->max() ?? 0;

            $board_item->values()->create(['column_id' => $column->id, 'value' => $next + 1]);
        }
    }

    /**
     * Notifies every person newly added to a people-type column value
     * (comparing against the currently-stored value), skipping self-assignment.
     * No-ops entirely when the column's own `config.notify_on_assignment` has
     * been switched off (see the People cell picker's bottom toggle,
     * persisted per-column via `BoardColumnController::update()`), which
     * takes precedence over, and is checked before ever touching, each
     * recipient's own personal notification preferences.
     */
    private function notifyNewlyAssignedPeople(WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column, mixed $new_value, User $actor): void
    {
        if (($column->config['notify_on_assignment'] ?? true) === false) {
            return;
        }

        $existing_value = BoardItemValue::where('item_id', $board_item->id)->where('column_id', $column->id)->first();
        $existing_ids = is_array($existing_value?->value) ? $existing_value->value : [];
        $new_ids = is_array($new_value) ? $new_value : [];

        foreach (array_diff($new_ids, $existing_ids) as $newly_added_id) {
            if ($person = User::find($newly_added_id)) {
                $this->notification_service->notify(
                    recipient: $person,
                    actor: $actor,
                    type: Notification::TYPE_ASSIGNED,
                    board: $item,
                    action_label: 'Assigned you',
                    action_target: sprintf('to "%s" on the Board "%s"', $board_item->name, $item->label),
                    link: "/boards/{$item->id}/pulses/{$board_item->id}",
                    board_item: $board_item,
                );
            }
        }
    }
}
