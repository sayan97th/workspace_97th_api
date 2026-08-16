<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that `user_id` was `@mentioned` in a {@link BoardComment} — stored
 * structurally (picked from `mentionable_people` on the frontend, not
 * regex-parsed from the body) so mentions stay correct even if two
 * teammates share a display name.
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
class BoardCommentMention extends Model
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
        return $this->belongsTo(User::class);
    }
}
