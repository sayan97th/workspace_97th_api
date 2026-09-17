<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that `user_id` has muted notifications for `board_id` — checked by
 * {@see \App\Services\Notification\NotificationService::notify()} ahead of
 * the recipient's own per-type preferences, so muting a board suppresses
 * every notification type for it in one place.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['user_id', 'board_id'])]
class BoardNotificationMute extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }
}
