<?php

namespace App\Models;

use App\Concerns\HasRandomBigId;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A single row ("pulse") on a board. Its id is a random 10-digit number (see
 * {@link HasRandomBigId}), which is the id used in `/boards/{board_id}/pulses/{id}`
 * deep links to open the item detail drawer.
 *
 * @property int $id
 * @property int $board_id
 * @property int $group_id
 * @property string $name
 * @property int $position
 * @property string|null $cover_image_path
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $cover_image_url
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardGroup $group
 * @property-read User|null $creator
 * @property-read Collection<int, BoardItemValue> $values
 * @property-read Collection<int, BoardItemComment> $comments
 */
#[Fillable(['board_id', 'group_id', 'name', 'position', 'cover_image_path', 'created_by_id'])]
#[Appends(['cover_image_url'])]
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
     * {@see \App\Http\Controllers\Board\BoardItemCommentController}).
     *
     * @return HasMany<BoardItemComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(BoardItemComment::class, 'item_id');
    }

    /**
     * Trello-style card cover, resolved to a public URL — see
     * {@see \App\Http\Controllers\Board\BoardItemController::updateCover()}.
     *
     * @return Attribute<string|null, never>
     */
    protected function coverImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cover_image_path ? Storage::disk('public')->url($this->cover_image_path) : null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
