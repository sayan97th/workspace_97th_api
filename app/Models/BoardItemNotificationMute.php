<?php

namespace App\Models;

use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that `user_id` has muted notifications for one board item, checked
 * by {@see NotificationService::notify()} next to
 * the board level {@see BoardNotificationMute}. Muting an item silences every
 * notification about it, its comment thread included.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_item_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read BoardItem $boardItem
 */
#[Fillable(['user_id', 'board_item_id'])]
class BoardItemNotificationMute extends Model
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
     * @return BelongsTo<BoardItem, $this>
     */
    public function boardItem(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }
}
