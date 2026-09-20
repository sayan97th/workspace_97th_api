<?php

namespace App\Concerns;

use App\Models\BoardComment;
use App\Models\BoardCommentAttachment;
use App\Models\BoardCommentBookmark;
use App\Models\BoardCommentLike;
use App\Models\BoardCommentMention;
use App\Models\BoardCommentNotify;
use App\Models\BoardCommentReaction;
use App\Models\BoardCommentRevision;
use App\Models\BoardCommentView;
use App\Models\BoardItemComment;
use App\Models\BoardItemCommentAttachment;
use App\Models\BoardItemCommentBookmark;
use App\Models\BoardItemCommentLike;
use App\Models\BoardItemCommentMention;
use App\Models\BoardItemCommentNotify;
use App\Models\BoardItemCommentReaction;
use App\Models\BoardItemCommentRevision;
use App\Models\BoardItemCommentView;
use App\Models\User;
use App\Services\Board\CommentThreadActionsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared behavior for {@see BoardItemComment} and
 * {@see BoardComment}, the two comment-thread models are
 * otherwise near-identical (item-scoped vs board-scoped), so every relation,
 * cast and scope both need lives here once instead of being hand-copied
 * onto each model.
 *
 * The including model must still define its own `item()`/`board()` parent
 * relation and `*_id` foreign key column, since those are the one thing
 * that genuinely differs between the two.
 */
trait HasCommentThread
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'edited_at' => 'datetime',
            'pinned' => 'boolean',
        ];
    }

    /**
     * The top-level comment this is a reply to, if any.
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * Replies to this comment, oldest first.
     *
     * @return HasMany<static, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->visibleNow()->orderBy('created_at');
    }

    /**
     * The user who wrote this comment.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return HasMany<BoardItemCommentLike|BoardCommentLike, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany($this->commentModel('Like'), 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentReaction|BoardCommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany($this->commentModel('Reaction'), 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentView|BoardCommentView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany($this->commentModel('View'), 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentMention|BoardCommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany($this->commentModel('Mention'), 'comment_id');
    }

    /**
     * People explicitly notified through the composer's "Notify" action,
     * distinct from {@see mentions()}, see {@see BoardItemCommentNotify}.
     *
     * @return HasMany<BoardItemCommentNotify|BoardCommentNotify, $this>
     */
    public function notifiedUsers(): HasMany
    {
        return $this->hasMany($this->commentModel('Notify'), 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentAttachment|BoardCommentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany($this->commentModel('Attachment'), 'comment_id');
    }

    /**
     * @return HasMany<BoardItemCommentBookmark|BoardCommentBookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany($this->commentModel('Bookmark'), 'comment_id');
    }

    /**
     * Earlier bodies this comment had before an edit replaced them, newest
     * edit first, see {@see CommentThreadActionsService::editBody()}.
     *
     * @return HasMany<BoardItemCommentRevision|BoardCommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany($this->commentModel('Revision'), 'comment_id')->orderByDesc('id');
    }

    /**
     * Resolves e.g. `Like` to `App\Models\BoardItemCommentLike` when included
     * by `BoardItemComment`, or `App\Models\BoardCommentLike` when included
     * by `BoardComment` — keeps every child-table relation above in sync
     * with whichever model the trait is mixed into, without repeating them.
     *
     * @return class-string
     */
    private function commentModel(string $suffix): string
    {
        return static::class.$suffix;
    }

    /**
     * Excludes comments still waiting on a future `scheduled_at` — the
     * normal comment thread and the Update Feed only ever query through this.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleNow(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
        });
    }

    /**
     * A user's own not-yet-published scheduled drafts — the Update Feed's
     * "Scheduled" tab.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScheduledBy(Builder $query, int $user_id): Builder
    {
        return $query->where('user_id', $user_id)->whereNotNull('scheduled_at')->where('scheduled_at', '>', now());
    }

    /**
     * Pinned comments first, then newest first — used wherever a thread or
     * feed wants pinned updates to sort ahead of the rest.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePinnedFirst(Builder $query): Builder
    {
        return $query->orderByDesc('pinned')->orderByDesc('created_at');
    }
}
