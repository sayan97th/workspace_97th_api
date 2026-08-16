<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's emoji reaction on a {@link BoardComment}. A user may react with
 * several different emoji on the same comment, but only once each (unique
 * `[comment_id, user_id, emoji]`).
 *
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property string $emoji
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardComment $comment
 * @property-read User $user
 */
#[Fillable(['comment_id', 'user_id', 'emoji'])]
class BoardCommentReaction extends Model
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
