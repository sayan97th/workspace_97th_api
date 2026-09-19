<?php

namespace App\Models;

use App\Console\Commands\Board\RunDueDateAutomationsCommand;
use App\Services\Board\BoardAutomationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rule-based automation recipe on a board tab: "when `trigger_column_id`
 * changes to `trigger_value`, run `action_type`" (a status/label/people
 * trigger), "when `trigger_column_id`'s date arrives, run `action_type`" (a
 * date trigger, checked daily by {@see RunDueDateAutomationsCommand}), or
 * "when an item/subitem is created on this tab, run `action_type`" (no
 * watched column at all — `trigger_column_id`/`trigger_value` are both null).
 * No AI involved: every rule is a fixed, user-configured condition/action pair.
 *
 * Communication actions ({@see self::ACTION_SEND_EMAIL}, {@see self::ACTION_SLACK_NOTIFY_CHANNEL},
 * {@see self::ACTION_SLACK_NOTIFY_PERSON}) reach people outside the app. They accept an optional
 * `action_params.message`, a template that may use the tokens listed on
 * {@see BoardAutomationService::renderMessage()}.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string|null $name
 * @property bool $is_enabled
 * @property string $trigger_type
 * @property int|null $trigger_column_id
 * @property mixed $trigger_value
 * @property string $action_type
 * @property array<string, mixed>|null $action_params
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read BoardColumn|null $triggerColumn
 */
#[Fillable([
    'board_id', 'board_view_id', 'name', 'is_enabled',
    'trigger_type', 'trigger_column_id', 'trigger_value',
    'action_type', 'action_params', 'created_by_id',
])]
class BoardAutomation extends Model
{
    /** Fires once a `status`/`label` column's value changes to `trigger_value`, checked synchronously from `BoardItemValueService::sync()`. */
    public const TRIGGER_STATUS_CHANGED = 'status_changed';

    /** Fires once a `date` column's stored date equals today — checked once daily by the scheduled `automations:run-date-triggers` command. */
    public const TRIGGER_DATE_ARRIVED = 'date_arrived';

    /** Fires once a new root item is created on this tab — no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_ITEM_CREATED = 'item_created';

    /** Fires once a new subitem is created under any item on this tab — no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_SUBITEM_CREATED = 'subitem_created';

    /** Fires once a `people` column gains a newly-assigned person — `trigger_value` is a specific user id to watch for, or null for "anyone". */
    public const TRIGGER_PERSON_ASSIGNED = 'person_assigned';

    /** Fires once any value written to a column of any type differs from what was stored, `trigger_column_id` is required and `trigger_value` is null. */
    public const TRIGGER_COLUMN_CHANGED = 'column_changed';

    /** Fires once a new update (comment, not a reply) is posted on an item of this tab, no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_UPDATE_POSTED = 'update_posted';

    /** Moves the item to `action_params.target_group_id`. */
    public const ACTION_MOVE_TO_GROUP = 'move_to_group';

    /** Notifies `action_params.notify_user_id`, or whoever `action_params.notify_from_people_column_id` currently holds. */
    public const ACTION_NOTIFY_PERSON = 'notify_person';

    /** Emails `action_params.notify_user_id`, or everyone `action_params.notify_from_people_column_id` currently holds, with an optional `subject` and `message`. */
    public const ACTION_SEND_EMAIL = 'send_email';

    /** Posts `action_params.message` to the Slack channel `action_params.slack_channel_id`. */
    public const ACTION_SLACK_NOTIFY_CHANNEL = 'slack_notify_channel';

    /** Sends `action_params.message` as a Slack direct message to the same recipients {@see self::ACTION_SEND_EMAIL} resolves. */
    public const ACTION_SLACK_NOTIFY_PERSON = 'slack_notify_person';

    /** Archives (soft-deletes) the item. */
    public const ACTION_ARCHIVE_ITEM = 'archive_item';

    /** Sets `action_params.target_column_id`'s value to `action_params.value` on the item. */
    public const ACTION_SET_COLUMN_VALUE = 'set_column_value';

    /** Creates a new item named `action_params.item_name` (or "New item") in `action_params.target_group_id`. */
    public const ACTION_CREATE_ITEM = 'create_item';

    /**
     * Every action that talks to someone outside the app.
     *
     * @return array<int, string>
     */
    public static function communicationActions(): array
    {
        return [self::ACTION_SEND_EMAIL, self::ACTION_SLACK_NOTIFY_CHANNEL, self::ACTION_SLACK_NOTIFY_PERSON];
    }

    /**
     * Every action that needs a connected Slack workspace to do anything.
     *
     * @return array<int, string>
     */
    public static function slackActions(): array
    {
        return [self::ACTION_SLACK_NOTIFY_CHANNEL, self::ACTION_SLACK_NOTIFY_PERSON];
    }

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
