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
 * A comment ("update") on a board item, or a reply when `parent_id` is set.
 * Only one level of nesting is allowed — enforced in
 * {@see \App\Http\Controllers\Board\BoardItemCommentController::store()}, not
 * at the schema level, mirroring `base_clients_api`'s `OrderSessionComment`.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read BoardItem $item
 * @property-read BoardItemComment|null $parent
 * @property-read Collection<int, BoardItemComment> $replies
 * @property-read User|null $author
 * @property-read Collection<int, BoardItemCommentLike> $likes
 * @property-read Collection<int, BoardItemCommentReaction> $reactions
 * @property-read Collection<int, BoardItemCommentView> $views
 * @property-read Collection<int, BoardItemCommentMention> $mentions
 * @property-read Collection<int, BoardItemCommentAttachment> $attachments
 */
#[Fillable(['item_id', 'parent_id', 'user_id', 'body'])]
class BoardItemComment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The board item this comment belongs to.
     *
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }

    /**
     * The top-level comment this is a reply to, if any.
     *
     * @return BelongsTo<BoardItemComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(BoardItemComment::class, 'parent_id');
    }

    /**
     * Replies to this comment, oldest first.
     *
     * @return HasMany<BoardItemComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(BoardItemComment::class, 'parent_id')->orderBy('created_at');
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
     * @return HasMany<BoardItemCommentLike, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(BoardItemCommentLike::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(BoardItemCommentReaction::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(BoardItemCommentView::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(BoardItemCommentMention::class, 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BoardItemCommentAttachment::class, 'comment_id');
    }
}
