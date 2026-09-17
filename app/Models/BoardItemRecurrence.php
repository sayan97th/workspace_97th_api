<?php

namespace App\Models;

use App\Services\Board\RecurringItemService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marks a root item to auto-recreate itself on a schedule (daily/weekly/
 * monthly) — the Row menu's "Set recurring..." action. Read and advanced
 * exclusively by {@see RecurringItemService::run()}, which the
 * `items:run-recurrences` scheduled command calls once a day.
 *
 * @property int $id
 * @property int $board_item_id
 * @property string $frequency
 * @property int $interval_count
 * @property string $next_run_date
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardItem $item
 */
#[Fillable(['board_item_id', 'frequency', 'interval_count', 'next_run_date', 'is_enabled'])]
class BoardItemRecurrence extends Model
{
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

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
            'next_run_date' => 'date',
            'is_enabled' => 'boolean',
        ];
    }
}
