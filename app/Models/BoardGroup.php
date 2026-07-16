<?php

namespace App\Models;

use Database\Factories\BoardGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A collapsible group of items on a board — renders as one of the board's
 * "tables" on the frontend ({@link \App\Http\Controllers\Board\BoardGroupController}).
 * A board can have any number of groups (1…N), which is how the "multiple
 * tables in one Main Table view" requirement is satisfied without any special
 * frontend concept beyond the existing `BoardGroup<TRow>` the board kit
 * already renders.
 *
 * @property int $id
 * @property int $board_id
 * @property string $name
 * @property string $accent_color
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read Collection<int, BoardItem> $items
 */
#[Fillable(['board_id', 'name', 'accent_color', 'position'])]
class BoardGroup extends Model
{
    /** @use HasFactory<BoardGroupFactory> */
    use HasFactory;

    /**
     * The board (navigation leaf) this group belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * Items (rows) inside this group, ordered for display.
     *
     * @return HasMany<BoardItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BoardItem::class, 'group_id')->orderBy('position');
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
