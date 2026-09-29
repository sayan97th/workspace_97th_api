<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The rest of an automation run waiting behind a "wait" step ({@see BoardAutomation::ACTION_WAIT}).
 * At `run_at`, `automations:run-delayed` continues the `branch` from `next_action_index` on the same
 * item, with what the trigger knew (`context`). A wait with `recheck_conditions` stops when the
 * item no longer passes the conditions by then.
 *
 * @property int $id
 * @property int $automation_id
 * @property int $board_id
 * @property int $board_view_id
 * @property int|null $board_item_id
 * @property int|null $actor_id
 * @property string|null $run_uuid
 * @property string $branch
 * @property int $next_action_index
 * @property bool $recheck_conditions
 * @property array<string, mixed>|null $context
 * @property Carbon $run_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardAutomation $automation
 * @property-read BoardItem|null $item
 */
#[Fillable([
    'automation_id', 'board_id', 'board_view_id', 'board_item_id', 'actor_id', 'run_uuid', 'branch',
    'next_action_index', 'recheck_conditions', 'context', 'run_at', 'status',
])]
class BoardAutomationDelayedRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return BelongsTo<BoardAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(BoardAutomation::class, 'automation_id');
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
            'recheck_conditions' => 'boolean',
            'context' => 'array',
            'run_at' => 'datetime',
        ];
    }
}
