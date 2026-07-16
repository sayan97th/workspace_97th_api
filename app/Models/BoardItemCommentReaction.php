<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's emoji reaction on a {@link BoardItemComment}. A user may react
 * with several different emoji on the same comment, but only once each
 * (unique `[comment_id, user_id, emoji]`).
 *
 * @property int $id
 * @property int $comment_id
 * @property int $user_id
 * @property string $emoji
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardItemComment $comment
 * @property-read User $user
 */
#[Fillable(['comment_id', 'user_id', 'emoji'])]
class BoardItemCommentReaction extends Model
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
