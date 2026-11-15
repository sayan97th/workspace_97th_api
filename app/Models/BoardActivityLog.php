<?php

namespace App\Models;

use App\Enums\BoardEditPermission;
use App\Services\Board\BoardActivityFeed;
use App\Services\Board\RecurringItemService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One structural entry in a board's Activity log: the board itself (renamed,
 * archived, permissions), its items as a whole (created, duplicated, archived,
 * moved to trash, restored), its groups and its columns (created, renamed,
 * archived, deleted). Cell changes live in {@see BoardItemActivity}, the
 * Activity log drawer reads both through {@see BoardActivityFeed}.
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $user_id
 * @property string $action
 * @property string $description
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $undone_at
 * @property Carbon $created_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read User|null $user
 */
#[Fillable(['board_id', 'user_id', 'action', 'description', 'meta', 'undone_at'])]
class BoardActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';

    public const ACTION_RENAMED = 'renamed';

    public const ACTION_TYPE_CHANGED = 'type_changed';

    public const ACTION_DUPLICATED = 'duplicated';

    public const ACTION_ARCHIVED = 'archived';

    public const ACTION_UNARCHIVED = 'unarchived';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_ITEM_ARCHIVED = 'item_archived';

    public const ACTION_ITEM_RESTORED = 'item_restored';

    public const ACTION_ITEM_DELETED = 'item_deleted';

    /** An item was moved into or out of this board through the item drawer's "Move to board" action. */
    public const ACTION_ITEM_MOVED = 'item_moved';

    /** A {@see BoardAutomation} rule fired and ran its action against an item. */
    public const ACTION_AUTOMATION_RAN = 'automation_ran';

    /** A {@see BoardItemRecurrence} fired and recreated its item — see {@see RecurringItemService}. */
    public const ACTION_ITEM_RECURRED = 'item_recurred';

    /** A board owner changed the board permission mode, see {@see BoardEditPermission}. */
    public const ACTION_PERMISSIONS_CHANGED = 'permissions_changed';

    /** A new item (or subitem) was added to the board. */
    public const ACTION_ITEM_CREATED = 'item_created';

    /** An item was duplicated, `meta.source_item_id` is the original. */
    public const ACTION_ITEM_DUPLICATED = 'item_duplicated';

    /** An item was moved to the trash (soft deleted), it can still be restored. */
    public const ACTION_ITEM_TRASHED = 'item_trashed';

    public const ACTION_GROUP_CREATED = 'group_created';

    public const ACTION_GROUP_RENAMED = 'group_renamed';

    public const ACTION_GROUP_ARCHIVED = 'group_archived';

    /** An archived group was brought back, from the archive panel or the Activity log's "Undo". */
    public const ACTION_GROUP_RESTORED = 'group_restored';

    public const ACTION_GROUP_DELETED = 'group_deleted';

    public const ACTION_GROUP_DUPLICATED = 'group_duplicated';

    public const ACTION_COLUMN_CREATED = 'column_created';

    public const ACTION_COLUMN_RENAMED = 'column_renamed';

    public const ACTION_COLUMN_DELETED = 'column_deleted';

    public const ACTION_COLUMN_DUPLICATED = 'column_duplicated';

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'undone_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
