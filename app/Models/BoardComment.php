<?php

namespace App\Models;

use App\Concerns\HasCommentThread;
use App\Http\Controllers\Board\BoardCommentController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment ("update") on a board's discussion feed, or a reply when
 * `parent_id` is set. Only one level of nesting is allowed, enforced in
 * {@see BoardCommentController::store()}, not at
 * the schema level, mirroring {@see BoardItemComment}. Shared relations/scopes
 * with {@see BoardItemComment} live in {@see HasCommentThread}.
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $edited_at
 * @property bool $pinned
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_id
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
 * @property-read Collection<int, BoardCommentNotify> $notifiedUsers
 * @property-read Collection<int, BoardCommentAttachment> $attachments
 * @property-read Collection<int, BoardCommentBookmark> $bookmarks
 */
#[Fillable(['board_id', 'parent_id', 'user_id', 'body', 'scheduled_at', 'edited_at', 'pinned', 'resolved_at', 'resolved_by_id'])]
class BoardComment extends Model
{
    use HasCommentThread, HasFactory, SoftDeletes;

    /**
     * The board this comment belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }
}
