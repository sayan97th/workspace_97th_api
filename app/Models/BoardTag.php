<?php

namespace App\Models;

use Database\Factories\BoardTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A board-wide Tags option (`#label` + color), shared across every `tags`-type
 * {@link BoardColumn} on the same board — mirroring monday.com, where the Tags
 * column's option list lives on the board (not owned per-column, unlike
 * Status/Dropdown's own `config.options`). Created inline from a Tags cell's
 * "Create new tag" or from the column's "Manage tags" modal.
 *
 * @property int $id
 * @property int $board_id
 * @property string $label
 * @property string $color
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 */
#[Fillable(['board_id', 'label', 'color', 'position'])]
class BoardTag extends Model
{
    /** @use HasFactory<BoardTagFactory> */
    use HasFactory;

    /**
     * The board (navigation leaf) this tag belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
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
