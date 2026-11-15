<?php

namespace App\Services\Board;

use App\Models\BoardActivityLog;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Str;

/**
 * Records one structural entry in a board's Activity log, see
 * {@see BoardActivityLog}. Cell changes are recorded separately by
 * {@see BoardItemActivityService}. The `logItem()`, `logGroup()` and
 * `logColumn()` helpers keep the names in `meta`, so an entry still reads
 * correctly after the item, group or column is renamed or deleted.
 */
class BoardActivityLogger
{
    /** Longest name kept in an entry's description. */
    private const MAX_NAME_LENGTH = 120;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(WorkspaceNavigationItem $board, ?User $user, string $action, string $description, array $meta = []): BoardActivityLog
    {
        return BoardActivityLog::create([
            'board_id' => $board->id,
            'user_id' => $user?->id,
            'action' => $action,
            'description' => Str::limit($description, 250, ''),
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * An event about one item as a whole, described as `{$verb} "{item name}"`.
     *
     * @param  array<string, mixed>  $extra_meta
     */
    public function logItem(WorkspaceNavigationItem $board, ?User $user, string $action, string $verb, BoardItem $board_item, array $extra_meta = []): BoardActivityLog
    {
        $board_item->loadMissing('group');
        $name = Str::limit((string) $board_item->name, self::MAX_NAME_LENGTH);

        return $this->log($board, $user, $action, "{$verb} \"{$name}\"", [
            'item_id' => $board_item->id,
            'item_name' => $name,
            'parent_id' => $board_item->parent_id,
            'group_id' => $board_item->group_id,
            'group_name' => $board_item->group?->name,
            'group_color' => $board_item->group?->accent_color,
            ...$extra_meta,
        ]);
    }

    /**
     * An event about one group (table), described as `{$verb} group "{name}"`.
     *
     * @param  array<string, mixed>  $extra_meta
     */
    public function logGroup(WorkspaceNavigationItem $board, ?User $user, string $action, string $verb, BoardGroup $group, array $extra_meta = []): BoardActivityLog
    {
        $name = Str::limit((string) $group->name, self::MAX_NAME_LENGTH);

        return $this->log($board, $user, $action, "{$verb} group \"{$name}\"", [
            'group_id' => $group->id,
            'group_name' => $name,
            'group_color' => $group->accent_color,
            ...$extra_meta,
        ]);
    }

    /**
     * An event about one column, described as `{$verb} column "{label}"`.
     *
     * @param  array<string, mixed>  $extra_meta
     */
    public function logColumn(WorkspaceNavigationItem $board, ?User $user, string $action, string $verb, BoardColumn $column, array $extra_meta = []): BoardActivityLog
    {
        $label = Str::limit((string) $column->label, self::MAX_NAME_LENGTH);

        return $this->log($board, $user, $action, "{$verb} column \"{$label}\"", [
            'column_id' => $column->id,
            'column_label' => $label,
            'column_type' => $column->type,
            ...$extra_meta,
        ]);
    }
}
