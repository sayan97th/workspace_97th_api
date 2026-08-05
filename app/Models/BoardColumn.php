<?php

namespace App\Models;

use App\Concerns\BelongsToBoardView;
use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single column definition on one tab (`board_view_id`) of a board, e.g.
 * "Status" or "Assigned to". The column `type` drives how {@link BoardItemValue}
 * values are shaped and how the frontend renders/edits cells. Columns are
 * independent per tab — two tabs on the same board may define the same `key`.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property int $position
 * @property int $width
 * @property array<string, mixed>|null $config
 * @property bool $hideable
 * @property bool $pinnable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read Collection<int, BoardItemValue> $values
 */
#[Fillable([
    'board_id',
    'board_view_id',
    'key',
    'label',
    'type',
    'position',
    'width',
    'config',
    'hideable',
    'pinnable',
])]
class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use BelongsToBoardView, HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_STATUS = 'status';

    public const TYPE_PEOPLE = 'people';

    public const TYPE_DATE = 'date';

    public const TYPE_TAGS = 'tags';

    public const TYPE_NUMBER = 'number';

    public const TYPE_CHECKBOX = 'checkbox';

    /**
     * The board (navigation leaf) this column belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The tab (view) this column belongs to.
     *
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
    }

    /**
     * Every item's value stored against this column.
     *
     * @return HasMany<BoardItemValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(BoardItemValue::class, 'column_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
            'width' => 'integer',
            'hideable' => 'boolean',
            'pinnable' => 'boolean',
        ];
    }
}
