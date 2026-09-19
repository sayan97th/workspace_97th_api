<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The comment composer's "Assign" action: turns a comment into a task by
 * adding people to the item's first People column and, optionally, setting the
 * item's first Date column as the due date. It writes through
 * {@see BoardItemValueService} so the "Assigned you" notification, automations
 * and the item activity entry all behave exactly like a manual cell edit.
 */
class CommentAssignmentService
{
    public function __construct(private readonly BoardItemValueService $value_service) {}

    /**
     * The People and Date columns an assignment on `$board_item` would write
     * to. Fails validation when the assignment needs a column the item's tab
     * does not have, so the caller can check before creating the comment.
     *
     * @return array{people: BoardColumn|null, date: BoardColumn|null}
     */
    public function resolveColumns(BoardItem $board_item, bool $needs_people, bool $needs_date): array
    {
        $scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;

        $columns = BoardColumn::query()
            ->where('board_view_id', $board_item->group->board_view_id)
            ->where('scope', $scope)
            ->whereIn('type', [BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_DATE])
            ->orderBy('position')
            ->get(['id', 'type', 'config', 'label']);

        $people = $columns->firstWhere('type', BoardColumn::TYPE_PEOPLE);
        $date = $columns->firstWhere('type', BoardColumn::TYPE_DATE);

        if ($needs_people && $people === null) {
            throw ValidationException::withMessages(['assign_user_ids' => 'This board has no People column to assign to.']);
        }

        if ($needs_date && $date === null) {
            throw ValidationException::withMessages(['assign_due_date' => 'This board has no Date column for a due date.']);
        }

        return ['people' => $people, 'date' => $date];
    }

    /**
     * Adds `$user_ids` (limited to members of the board's workspace) to the
     * item's People column and sets `$due_date` on its Date column.
     *
     * @param  Collection<int, int>  $user_ids
     */
    public function assign(WorkspaceNavigationItem $board, BoardItem $board_item, Collection $user_ids, ?string $due_date, User $actor): void
    {
        $member_ids = $board->workspace->users()
            ->whereIn('users.id', $user_ids->all())
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id);

        $columns = $this->resolveColumns($board_item, $member_ids->isNotEmpty(), $due_date !== null);
        $values = [];

        if ($member_ids->isNotEmpty() && $columns['people'] !== null) {
            $existing = BoardItemValue::where('item_id', $board_item->id)->where('column_id', $columns['people']->id)->first()?->value;

            $values[$columns['people']->id] = collect(is_array($existing) ? $existing : [])
                ->merge($member_ids)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($due_date !== null && $columns['date'] !== null) {
            $values[$columns['date']->id] = $due_date;
        }

        if ($values !== []) {
            $this->value_service->sync($board, $board_item, $values, $actor);
        }
    }
}
