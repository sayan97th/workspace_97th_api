<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a board's "Activity log" (its "..." menu item) — a coarse,
 * board-level timeline of who did what and when: renamed the board, changed
 * its type, duplicated it, archived/unarchived it, or deleted it. This is
 * deliberately scoped to board-lifecycle events rather than every single
 * item/column/group edit, which already has its own surface (the item
 * drawer's comments, the board's discussion feed).
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $user_id
 * @property string $action
 * @property string $description
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read User|null $user
 */
#[Fillable(['board_id', 'user_id', 'action', 'description', 'meta'])]
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
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
