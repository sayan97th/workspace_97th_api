<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A board one user hid from their Update Feed (Inbox), monday.com's "Hide
 * board updates from inbox". Its updates leave every feed tab but
 * Bookmarked, the board filter list and the unread counts, until the user
 * shows it again from Feed settings.
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
class FeedHiddenBoard extends Model
{
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
