<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's "seen" mark on a {@link BoardItemComment}. Backs both the
 * comment's `view_count` (a simple count of these rows) and the drawer's
 * "mark as seen" toggle (this row is created/deleted for the current user).
 *
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardItemComment $comment
 * @property-read User $user
 */
#[Fillable(['comment_id', 'user_id'])]
class BoardItemCommentView extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<BoardItemComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(BoardItemComment::class, 'comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
