<?php

namespace App\Models;

use App\Concerns\BelongsToBoardView;
use Database\Factories\BoardGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A collapsible group of items on one tab (`board_view_id`) of a board —
 * renders as one of that tab's "tables" on the frontend
 * ({@link \App\Http\Controllers\Board\BoardGroupController}). A tab can have
 * any number of groups (1…N), which is how the "multiple tables in one tab"
 * requirement is satisfied without any special frontend concept beyond the
 * existing `BoardGroup<TRow>` the board kit already renders. Groups (and
 * therefore their items) are independent per tab.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string $name
 * @property string $accent_color
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read Collection<int, BoardItem> $items
 */
#[Fillable(['board_id', 'board_view_id', 'name', 'accent_color', 'position'])]
class BoardGroup extends Model
{
    /** @use HasFactory<BoardGroupFactory> */
    use BelongsToBoardView, HasFactory;

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
     * The tab (view) this group belongs to.
     *
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
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
