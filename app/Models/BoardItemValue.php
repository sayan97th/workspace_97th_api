<?php

namespace App\Models;

use Database\Factories\BoardItemValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cell's value: a single item's value for a single column. The `value`
 * shape depends on the owning column's `type` (string for text/status, an
 * array of user ids for people, an ISO date string for date, an array of
 * strings for tags, a number, or a bool for checkbox).
 *
 * @property int $id
 * @property int $item_id
 * @property int $column_id
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardItem $item
 * @property-read BoardColumn $column
 */
#[Fillable(['item_id', 'column_id', 'value'])]
class BoardItemValue extends Model
{
    /** @use HasFactory<BoardItemValueFactory> */
    use HasFactory;

    /**
     * The item this value belongs to.
     *
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'item_id');
    }

    /**
     * The column this value is for.
     *
     * @return BelongsTo<BoardColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
