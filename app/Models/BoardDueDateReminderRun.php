<?php

namespace App\Models;

use App\Services\Board\DueDateReminderService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marks that `column_id`'s reminder already notified for `board_item_id` on
 * `ran_on` — see {@see DueDateReminderService::run()}. Dedupes the daily
 * scheduled check, mirroring {@see BoardAutomationRun}.
 *
 * @property int $id
 * @property int $column_id
 * @property int $board_item_id
 * @property string $ran_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardColumn $column
 * @property-read BoardItem $item
 */
#[Fillable(['column_id', 'board_item_id', 'ran_on'])]
class BoardDueDateReminderRun extends Model
{
    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    /**
     * @return BelongsTo<BoardItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ran_on' => 'date',
        ];
    }
}
