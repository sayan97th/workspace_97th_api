<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that `user_id` was explicitly notified about a
 * {@link BoardComment} through the composer's "Notify" action, distinct
 * from {@see BoardCommentMention}: a notified user is not shown inline in
 * the comment body the way an `@mention` is.
 *
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardComment $comment
 * @property-read User $user
 */
#[Fillable(['comment_id', 'user_id'])]
class BoardCommentNotify extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<BoardComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(BoardComment::class, 'comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
