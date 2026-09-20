<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A body that {@see BoardItemComment} had before an edit replaced it, kept so the
 * "(edited)" marker can show earlier versions. `body_written_at` is when that
 * version was written (the comment's previous edit time, or its creation),
 * `created_at` is when the edit that replaced it happened.
 *
 * @property int $id
 * @property int $comment_id
 * @property int|null $edited_by_id
 * @property string $body
 * @property Carbon|null $body_written_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardItemComment $comment
 * @property-read User|null $editor
 */
#[Fillable(['comment_id', 'edited_by_id', 'body', 'body_written_at'])]
class BoardItemCommentRevision extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_written_at' => 'datetime',
        ];
    }

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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id')->withTrashed();
    }
}
