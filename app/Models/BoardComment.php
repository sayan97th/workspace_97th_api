<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment ("update") on a board's discussion feed, or a reply when
 * `parent_id` is set. Only one level of nesting is allowed, enforced in
 * {@see \App\Http\Controllers\Board\BoardCommentController::store()}, not at
 * the schema level, mirroring {@see BoardItemComment}.
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardComment|null $parent
 * @property-read Collection<int, BoardComment> $replies
 * @property-read User|null $author
 * @property-read Collection<int, BoardCommentLike> $likes
 * @property-read Collection<int, BoardCommentReaction> $reactions
 * @property-read Collection<int, BoardCommentView> $views
 * @property-read Collection<int, BoardCommentMention> $mentions
 * @property-read Collection<int, BoardCommentAttachment> $attachments
 */
#[Fillable(['board_id', 'parent_id', 'user_id', 'body', 'edited_at'])]
class BoardComment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * The board this comment belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The top-level comment this is a reply to, if any.
     *
     * @return BelongsTo<BoardComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(BoardComment::class, 'parent_id');
    }

    /**
     * Replies to this comment, oldest first.
     *
     * @return HasMany<BoardComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(BoardComment::class, 'parent_id')->orderBy('created_at');
    }

    /**
     * The user who wrote this comment.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<BoardCommentLike, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(BoardCommentLike::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardCommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(BoardCommentReaction::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardCommentView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(BoardCommentView::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardCommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(BoardCommentMention::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardCommentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BoardCommentAttachment::class, 'comment_id');
    }
}
