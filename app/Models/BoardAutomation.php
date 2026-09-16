<?php

namespace App\Models;

use App\Console\Commands\Board\RunDueDateAutomationsCommand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rule-based automation recipe on a board tab: "when `trigger_column_id`
 * changes to `trigger_value`, run `action_type`" (a status/label trigger) or
 * "when `trigger_column_id`'s date arrives, run `action_type`" (a date
 * trigger, checked daily by {@see RunDueDateAutomationsCommand}).
 * No AI involved: every rule is a fixed, user-configured condition/action pair.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string|null $name
 * @property bool $is_enabled
 * @property string $trigger_type
 * @property int $trigger_column_id
 * @property mixed $trigger_value
 * @property string $action_type
 * @property array<string, mixed>|null $action_params
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read BoardColumn $triggerColumn
 */
#[Fillable([
    'board_id', 'board_view_id', 'name', 'is_enabled',
    'trigger_type', 'trigger_column_id', 'trigger_value',
    'action_type', 'action_params', 'created_by_id',
])]
class BoardAutomation extends Model
{
    /** Fires once a `status`/`label` column's value changes to `trigger_value` — checked synchronously from `BoardItemController::syncValues()`. */
    public const TRIGGER_STATUS_CHANGED = 'status_changed';

    /** Fires once a `date` column's stored date equals today — checked once daily by the scheduled `automations:run-date-triggers` command. */
    public const TRIGGER_DATE_ARRIVED = 'date_arrived';

    /** Moves the item to `action_params.target_group_id`. */
    public const ACTION_MOVE_TO_GROUP = 'move_to_group';

    /** Notifies `action_params.notify_user_id`, or whoever `action_params.notify_from_people_column_id` currently holds. */
    public const ACTION_NOTIFY_PERSON = 'notify_person';

    /**
     * The board (navigation leaf) this automation belongs to.
     *
     * @return BelongsTo<WorkspaceNavigationItem, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceNavigationItem::class, 'board_id');
    }

    /**
     * The tab (view) this automation is scoped to — an automation only ever
     * reacts to items on this one tab, mirroring how columns/groups are
     * themselves per-tab.
     *
     * @return BelongsTo<BoardView, $this>
     */
    public function boardView(): BelongsTo
    {
        return $this->belongsTo(BoardView::class, 'board_view_id');
    }

    /**
     * The column this automation watches.
     *
     * @return BelongsTo<BoardColumn, $this>
     */
    public function triggerColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'trigger_column_id');
    }

    /**
     * The user who created this automation.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'trigger_value' => 'array',
            'action_params' => 'array',
        ];
    }
}
