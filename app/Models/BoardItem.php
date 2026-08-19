<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use App\Http\Controllers\Board\BoardItemCommentController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single row ("pulse") on a board. Its id is a random 10-digit number (see
 * {@link HasRandomBigId}), which is the id used in `/boards/{board_id}/pulses/{id}`
 * deep links to open the item detail drawer.
 *
 * @property int $id
 * @property int $board_id
 * @property int $group_id
 * @property string $name
 * @property string|null $description
 * @property int $position
 * @property bool $is_archived
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardGroup $group
 * @property-read User|null $creator
 * @property-read Collection<int, BoardItemValue> $values
 * @property-read Collection<int, BoardItemComment> $comments
 * @property-read Collection<int, BoardItemCommentAttachment> $commentAttachments
 * @property-read Collection<int, BoardItemChecklistItem> $checklistItems
 */
#[Fillable(['board_id', 'group_id', 'name', 'description', 'position', 'is_archived', 'created_by_id'])]
class BoardItem extends Model
{
    use HasFactory, HasRandomBigId, SoftDeletes;

    /** The id is a randomly-generated 10-digit number, not an auto-increment. */
    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * The board (navigation leaf) this item belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The group ("table") this item currently sits in.
     *
     * @return BelongsTo<BoardGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BoardGroup::class, 'group_id');
    }

    /**
     * The user who created this item.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * This item's column values, keyed by column via `column_id`.
     *
     * @return HasMany<BoardItemValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(BoardItemValue::class, 'item_id');
    }

    /**
     * This item's comments/updates, including replies (see
     * {@see BoardItemCommentController}).
     *
     * @return HasMany<BoardItemComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(BoardItemComment::class, 'item_id');
    }

    /**
     * Every attachment across this item's comments, for the card's
     * "archivos adjuntos" count (see {@see BoardItemController::index()}'s
     * `withCount('commentAttachments')`) — mirrors the `comments` count's
     * `withCount()` pattern rather than a raw subquery.
     *
     * @return HasManyThrough<BoardItemCommentAttachment, BoardItemComment, $this>
     */
    public function commentAttachments(): HasManyThrough
    {
        return $this->hasManyThrough(BoardItemCommentAttachment::class, BoardItemComment::class, 'item_id', 'comment_id');
    }

    /**
     * Files attached directly to this item (the Kanban drawer's
     * "Attachments" affordance) — independent of {@see comments}, see
     * {@see BoardItemAttachment}.
     *
     * @return HasMany<BoardItemAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BoardItemAttachment::class, 'item_id');
    }

    /**
     * This item's subtask checklist lines, in display order — powers the
     * Kanban card's "✓ done/total" badge and the drawer's Subtasks section.
     *
     * @return HasMany<BoardItemChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(BoardItemChecklistItem::class, 'item_id')->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_archived' => 'boolean',
        ];
    }
}
