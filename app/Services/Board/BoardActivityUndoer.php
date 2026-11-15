<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardEditGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The board Activity log's "Undo" button, monday.com style: puts back what one
 * entry changed. Undoing is itself a change, so it goes through the same
 * write paths as a normal edit (and is logged like one), then the entry is
 * marked as undone so it can not be undone twice.
 *
 * What can be undone: a cell change, an item rename or group move, an item
 * created, archived or moved to the trash, a group archived or renamed, and a
 * column renamed. {@see BoardActivityFeed} tells the drawer which entries
 * qualify, this class checks again before writing anything.
 */
class BoardActivityUndoer
{
    public function __construct(
        private readonly BoardItemValueService $value_service,
        private readonly BoardItemActivityService $activity_service,
        private readonly BoardActivityLogger $activity_logger,
        private readonly BoardAutomationService $automation_service,
        private readonly ColumnPermissionService $column_permissions,
        private readonly BoardActivityFeed $feed,
    ) {}

    /**
     * @return array{message: string, item_ids: array<int, int>, needs_structure_refresh: bool}
     */
    public function undo(WorkspaceNavigationItem $board, User $user, string $source, int $entry_id): array
    {
        return $source === BoardActivityFeed::SOURCE_CELL
            ? $this->undoCellChange($board, $user, $entry_id)
            : $this->undoBoardEvent($board, $user, $entry_id);
    }

    /**
     * @return array{message: string, item_ids: array<int, int>, needs_structure_refresh: bool}
     */
    private function undoCellChange(WorkspaceNavigationItem $board, User $user, int $entry_id): array
    {
        $activity = BoardItemActivity::query()
            ->whereKey($entry_id)
            ->whereIn('item_id', BoardItem::withTrashed()->where('board_id', $board->id)->select('id'))
            ->firstOrFail();

        $this->ensureNotUndone($activity->undone_at !== null);
        if (! $activity->is_undoable) {
            $this->fail('This change was recorded before Undo was available, so it can not be undone.');
        }

        $board_item = BoardItem::withTrashed()->with('group')->findOrFail($activity->item_id);
        if ($board_item->trashed()) {
            $this->fail('The item is in the trash. Restore it first.');
        }
        BoardEditGate::authorizeItem($board, $user, $board_item);

        $needs_structure_refresh = false;

        DB::transaction(function () use ($board, $user, $activity, $board_item, &$needs_structure_refresh) {
            match ($activity->column_type) {
                BoardItemActivityService::FIELD_NAME => $this->renameItemBack($board_item, (string) $activity->old_value, $user),
                BoardItemActivityService::FIELD_GROUP => $needs_structure_refresh = $this->moveItemBack($board, $board_item, (int) $activity->old_value, $user),
                default => $this->writeValueBack($board, $board_item, $activity, $user),
            };

            $activity->update(['undone_at' => now()]);
        });

        return [
            'message' => 'Change undone.',
            'item_ids' => [$this->rootIdOf($board_item)],
            'needs_structure_refresh' => $needs_structure_refresh,
        ];
    }

    private function renameItemBack(BoardItem $board_item, string $old_name, User $user): void
    {
        if (trim($old_name) === '') {
            $this->fail('The item had no name to go back to.');
        }

        $current_name = (string) $board_item->name;
        $board_item->update(['name' => $old_name]);

        $this->activity_service->recordField($board_item, BoardItemActivityService::FIELD_NAME, 'Name', $current_name, $old_name, $user, $current_name, $old_name);
        $this->automation_service->handleNameChanged($board_item->fresh('group'), $current_name, $user);
    }

    /**
     * Moves the item back to the group it came from, at the end of it. Returns
     * true, the tables on both sides need reloading.
     */
    private function moveItemBack(WorkspaceNavigationItem $board, BoardItem $board_item, int $old_group_id, User $user): bool
    {
        $old_group = BoardGroup::where('board_id', $board->id)->find($old_group_id);
        if (! $this->feed->groupAcceptsUndo($old_group, $board_item->group)) {
            $this->fail('The group this item came from was deleted, archived or is on another tab.');
        }

        $from_group_id = (int) $board_item->group_id;
        if ($from_group_id === $old_group->id) {
            return false;
        }

        $board_item->update([
            'group_id' => $old_group->id,
            'position' => (int) $board->items()->where('group_id', $old_group->id)->where('parent_id', $board_item->parent_id)->max('position') + 1,
        ]);
        $this->cascadeGroup($board_item, $old_group->id);

        $this->activity_service->recordField(
            $board_item,
            BoardItemActivityService::FIELD_GROUP,
            'Group',
            BoardGroup::whereKey($from_group_id)->value('name'),
            $old_group->name,
            $user,
            $from_group_id,
            $old_group->id,
        );
        $this->automation_service->handleItemMoved($board_item->fresh('group'), $from_group_id, $user);

        return true;
    }

    private function writeValueBack(WorkspaceNavigationItem $board, BoardItem $board_item, BoardItemActivity $activity, User $user): void
    {
        $column = BoardColumn::where('board_id', $board->id)->find($activity->column_id);
        if ($column === null) {
            $this->fail('The column of this change was deleted.');
        }

        $this->column_permissions->authorizeEdit($board, $user, [$column->id]);
        $this->value_service->sync($board, $board_item, [$column->id => $activity->old_value], $user);
    }

    /**
     * @return array{message: string, item_ids: array<int, int>, needs_structure_refresh: bool}
     */
    private function undoBoardEvent(WorkspaceNavigationItem $board, User $user, int $entry_id): array
    {
        $entry = BoardActivityLog::where('board_id', $board->id)->findOrFail($entry_id);
        $this->ensureNotUndone($entry->undone_at !== null);

        $meta = $entry->meta ?? [];
        $item_ids = [];

        DB::transaction(function () use ($board, $user, $entry, $meta, &$item_ids) {
            match ($entry->action) {
                BoardActivityLog::ACTION_ITEM_CREATED => $item_ids = $this->trashCreatedItem($board, $user, (int) ($meta['item_id'] ?? 0)),
                BoardActivityLog::ACTION_ITEM_ARCHIVED => $item_ids = $this->unarchiveItem($board, $user, (int) ($meta['item_id'] ?? 0)),
                BoardActivityLog::ACTION_ITEM_TRASHED => $item_ids = $this->restoreTrashedItem($board, $user, (int) ($meta['item_id'] ?? 0)),
                BoardActivityLog::ACTION_GROUP_ARCHIVED => $this->restoreGroup($board, $user, (int) ($meta['group_id'] ?? 0)),
                BoardActivityLog::ACTION_GROUP_RENAMED => $this->renameGroupBack($board, $user, (int) ($meta['group_id'] ?? 0), (string) ($meta['old_name'] ?? '')),
                BoardActivityLog::ACTION_COLUMN_RENAMED => $this->renameColumnBack($board, $user, (int) ($meta['column_id'] ?? 0), (string) ($meta['old_name'] ?? '')),
                default => $this->fail('This activity can not be undone.'),
            };

            $entry->update(['undone_at' => now()]);
        });

        return [
            'message' => 'Change undone.',
            'item_ids' => $item_ids,
            'needs_structure_refresh' => true,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function trashCreatedItem(WorkspaceNavigationItem $board, User $user, int $item_id): array
    {
        $board_item = $board->items()->with('group')->find($item_id);
        if ($board_item === null) {
            $this->fail('This item was already deleted.');
        }
        BoardEditGate::authorizeItem($board, $user, $board_item);

        $root_id = $this->rootIdOf($board_item);
        $this->activity_logger->logItem($board, $user, BoardActivityLog::ACTION_ITEM_TRASHED, 'Deleted', $board_item);

        $board_item->loadMissing('childrenRecursive');
        foreach ($this->flatten($board_item->childrenRecursive) as $descendant) {
            $descendant->delete();
        }
        $board_item->delete();

        $this->automation_service->handleItemDeleted($board_item, $user);

        return [$root_id];
    }

    /**
     * @return array<int, int>
     */
    private function unarchiveItem(WorkspaceNavigationItem $board, User $user, int $item_id): array
    {
        $board_item = $board->items()->with('group')->find($item_id);
        if ($board_item === null || ! $board_item->is_archived) {
            $this->fail('This item is no longer archived.');
        }
        BoardEditGate::authorizeItem($board, $user, $board_item);

        $board_item->update(['is_archived' => false]);
        $this->activity_logger->logItem($board, $user, BoardActivityLog::ACTION_ITEM_RESTORED, 'Restored', $board_item);
        $this->automation_service->handleItemRestored($board_item->fresh('group'), $user);

        return [$this->rootIdOf($board_item)];
    }

    /**
     * Brings the item back from the trash together with the subitems that
     * were deleted with it.
     *
     * @return array<int, int>
     */
    private function restoreTrashedItem(WorkspaceNavigationItem $board, User $user, int $item_id): array
    {
        $board_item = BoardItem::onlyTrashed()->where('board_id', $board->id)->find($item_id);
        if ($board_item === null) {
            $this->fail('This item is no longer in the trash.');
        }
        BoardEditGate::authorizeContent($board, $user);

        $deleted_at = $board_item->deleted_at;
        $board_item->restore();
        $this->restoreTrashedDescendants($board_item->id, $deleted_at);

        $this->activity_logger->logItem($board, $user, BoardActivityLog::ACTION_ITEM_RESTORED, 'Restored', $board_item);
        $this->automation_service->handleItemRestored($board_item->fresh('group'), $user);

        return [$this->rootIdOf($board_item)];
    }

    private function restoreTrashedDescendants(int $parent_id, mixed $deleted_at): void
    {
        $children = BoardItem::onlyTrashed()->where('parent_id', $parent_id)->where('deleted_at', '>=', $deleted_at->copy()->subMinute())->get();

        foreach ($children as $child) {
            $child->restore();
            $this->restoreTrashedDescendants($child->id, $deleted_at);
        }
    }

    private function restoreGroup(WorkspaceNavigationItem $board, User $user, int $group_id): void
    {
        BoardEditGate::authorizeStructure($board, $user);

        $group = BoardGroup::where('board_id', $board->id)->where('is_archived', true)->find($group_id);
        if ($group === null) {
            $this->fail('This group is no longer archived.');
        }

        $group->update([
            'is_archived' => false,
            'archived_at' => null,
            'position' => (int) BoardGroup::where('board_view_id', $group->board_view_id)->where('is_archived', false)->max('position') + 1,
        ]);
        $this->activity_logger->logGroup($board, $user, BoardActivityLog::ACTION_GROUP_RESTORED, 'Restored', $group);
    }

    private function renameGroupBack(WorkspaceNavigationItem $board, User $user, int $group_id, string $old_name): void
    {
        BoardEditGate::authorizeStructure($board, $user);

        $group = BoardGroup::where('board_id', $board->id)->find($group_id);
        if ($group === null || trim($old_name) === '') {
            $this->fail('This group was deleted.');
        }

        $current_name = (string) $group->name;
        $group->update(['name' => $old_name]);

        $this->activity_logger->log(
            $board,
            $user,
            BoardActivityLog::ACTION_GROUP_RENAMED,
            "Renamed group \"{$current_name}\" to \"{$old_name}\"",
            ['group_id' => $group->id, 'group_name' => $old_name, 'group_color' => $group->accent_color, 'old_name' => $current_name, 'new_name' => $old_name],
        );
    }

    private function renameColumnBack(WorkspaceNavigationItem $board, User $user, int $column_id, string $old_name): void
    {
        BoardEditGate::authorizeStructure($board, $user);

        $column = BoardColumn::where('board_id', $board->id)->find($column_id);
        if ($column === null || trim($old_name) === '') {
            $this->fail('This column was deleted.');
        }

        $current_label = (string) $column->label;
        $column->update(['label' => $old_name]);

        $this->activity_logger->log(
            $board,
            $user,
            BoardActivityLog::ACTION_COLUMN_RENAMED,
            "Renamed column \"{$current_label}\" to \"{$old_name}\"",
            ['column_id' => $column->id, 'column_label' => $old_name, 'column_type' => $column->type, 'old_name' => $current_label, 'new_name' => $old_name],
        );
    }

    /**
     * The top level item a row belongs to, the board reloads rows by their root.
     */
    private function rootIdOf(BoardItem $board_item): int
    {
        $current = $board_item;
        $guard = 0;
        while ($current->parent_id !== null && $guard++ < 20) {
            $parent = BoardItem::withTrashed()->find($current->parent_id);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }

        return $current->id;
    }

    private function cascadeGroup(BoardItem $board_item, int $group_id): void
    {
        $board_item->loadMissing('childrenRecursive');
        foreach ($this->flatten($board_item->childrenRecursive) as $descendant) {
            $descendant->update(['group_id' => $group_id]);
        }
    }

    /**
     * @param  iterable<BoardItem>  $items
     * @return array<int, BoardItem>
     */
    private function flatten(iterable $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            $flat[] = $item;
            if ($item->relationLoaded('childrenRecursive')) {
                array_push($flat, ...$this->flatten($item->childrenRecursive));
            }
        }

        return $flat;
    }

    private function ensureNotUndone(bool $is_undone): void
    {
        if ($is_undone) {
            $this->fail('This change was already undone.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['entry' => $message]);
    }
}
