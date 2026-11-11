<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An unsent update or reply, saved while the user types so it is still there
 * when they come back to the item or the board discussion, like Slack's
 * drafts. Private to `user_id`. `body` is Markdown, the same format the
 * comment composer produces, and the picked `@mention` and "Notify" people
 * travel with it so a restored draft still notifies them once sent.
 *
 * @property int $id
 * @property int $user_id
 * @property int $board_id
 * @property int|null $item_id
 * @property int|null $parent_id
 * @property string $draft_key
 * @property string $body
 * @property array<int, int>|null $mentioned_user_ids
 * @property array<int, int>|null $notified_user_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardItem|null $item
 */
#[Fillable(['user_id', 'board_id', 'item_id', 'parent_id', 'draft_key', 'body', 'mentioned_user_ids', 'notified_user_ids'])]
class CommentDraft extends Model
{
    /** Most drafts one user keeps, the oldest ones are dropped past it. */
    public const MAX_PER_USER = 200;

    /** Longest draft body accepted. Larger than a sent update's limit, so an overlong draft is kept rather than lost. */
    public const MAX_BODY_LENGTH = 20000;

    protected function casts(): array
    {
        return [
            'mentioned_user_ids' => 'array',
            'notified_user_ids' => 'array',
        ];
    }

    /**
     * The one key a composer's draft is stored under, unique per user.
     */
    public static function keyFor(int $board_id, ?int $item_id, ?int $parent_id): string
    {
        return sprintf('board:%d:item:%s:parent:%s', $board_id, $item_id ?? 'none', $parent_id ?? 'none');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }
}
