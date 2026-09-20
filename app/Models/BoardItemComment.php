<?php

namespace App\Models;

use App\Concerns\HasCommentThread;
use App\Http\Controllers\Board\BoardItemCommentController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment ("update") on a board item, or a reply when `parent_id` is set.
 * Only one level of nesting is allowed — enforced in
 * {@see BoardItemCommentController::store()}, not
 * at the schema level, mirroring `base_clients_api`'s `OrderSessionComment`.
 * Shared relations/scopes with {@see BoardComment} live in {@see HasCommentThread}.
 *
 * @property int $id
 * @property int $item_id
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
 * @property-read BoardItem $item
 * @property-read BoardItemComment|null $parent
 * @property-read Collection<int, BoardItemComment> $replies
 * @property-read User|null $author
 * @property-read Collection<int, BoardItemCommentLike> $likes
 * @property-read Collection<int, BoardItemCommentReaction> $reactions
 * @property-read Collection<int, BoardItemCommentView> $views
 * @property-read Collection<int, BoardItemCommentMention> $mentions
 * @property-read Collection<int, BoardItemCommentNotify> $notifiedUsers
 * @property-read Collection<int, BoardItemCommentAttachment> $attachments
 * @property-read Collection<int, BoardItemCommentBookmark> $bookmarks
 */
#[Fillable(['item_id', 'parent_id', 'user_id', 'body', 'scheduled_at', 'edited_at', 'pinned', 'resolved_at', 'resolved_by_id'])]
class BoardItemComment extends Model
{
    use HasCommentThread, HasFactory, SoftDeletes;

    /**
     * The board item this comment belongs to.
     *
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }
}
