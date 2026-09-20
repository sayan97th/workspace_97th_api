<?php

namespace App\Models;

use App\Services\Board\BoardAutomationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time {@see BoardAutomation} was asked to run, written by {@see BoardAutomationService}.
 * Shown under the Manage tab's "Run history" and summed for its usage numbers. Distinct from
 * {@see BoardAutomationRun}, which only dedupes the daily date trigger.
 *
 * `status` is {@see self::STATUS_SUCCESS} when the action did its job, {@see self::STATUS_SKIPPED}
 * when there was nothing to do (nobody to notify, the item was already in the target table) and
 * {@see self::STATUS_FAILED} when it could not be delivered or threw.
 *
 * @property int $id
 * @property int|null $automation_id
 * @property int $board_id
 * @property int $board_view_id
 * @property int|null $board_item_id
 * @property int|null $actor_id
 * @property string|null $automation_name
 * @property string|null $item_name
 * @property string $trigger_type
 * @property string $action_type
 * @property string $status
 * @property string $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BoardAutomation|null $automation
 * @property-read User|null $actor
 */
#[Fillable([
    'automation_id', 'board_id', 'board_view_id', 'board_item_id', 'actor_id',
    'automation_name', 'item_name', 'trigger_type', 'action_type', 'status', 'message',
])]
class BoardAutomationRunLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_SKIPPED];
    }

    /**
     * @return BelongsTo<BoardAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(BoardAutomation::class, 'automation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
