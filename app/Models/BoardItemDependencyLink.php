<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How one item (`item_id`) is scheduled from one of its predecessors (`predecessor_id`) on a
 * Dependency column, mirroring monday.com's dependency type and lag. The cell value of that
 * column keeps listing the predecessor ids, see {@see BoardColumn::TYPE_DEPENDENCY}.
 *
 * - `fs` finish to start: starts after the predecessor ends.
 * - `ss` start to start: starts when the predecessor starts.
 * - `ff` finish to finish: ends when the predecessor ends.
 * - `sf` start to finish: ends when the predecessor starts.
 *
 * `lag_days` moves that point later (positive) or earlier (negative, a lead). On a Date column,
 * which has one day only, every type means "the predecessor's date plus the lag".
 *
 * @property int $id
 * @property int $column_id
 * @property int $item_id
 * @property int $predecessor_id
 * @property string $type
 * @property int $lag_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['column_id', 'item_id', 'predecessor_id', 'type', 'lag_days'])]
class BoardItemDependencyLink extends Model
{
    public const TYPE_FINISH_TO_START = 'fs';

    public const TYPE_START_TO_START = 'ss';

    public const TYPE_FINISH_TO_FINISH = 'ff';

    public const TYPE_START_TO_FINISH = 'sf';

    public const TYPES = [self::TYPE_FINISH_TO_START, self::TYPE_START_TO_START, self::TYPE_FINISH_TO_FINISH, self::TYPE_START_TO_FINISH];

    /** A lag beyond about two years is a typo, not a plan. */
    public const MAX_LAG_DAYS = 730;

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'predecessor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lag_days' => 'integer',
        ];
    }
}
