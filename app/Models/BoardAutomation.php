<?php

namespace App\Models;

use App\Console\Commands\Board\RunDueDateAutomationsCommand;
use App\Console\Commands\Board\RunScheduledAutomationsCommand;
use App\Services\Board\BoardAutomationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rule-based automation on a board tab, built with the monday style sentence builder:
 * "When <trigger>, and only if <conditions>, then <action>, <action>, ...". No AI involved:
 * every rule is a fixed, user-configured trigger, condition and action list.
 *
 * - The trigger is `trigger_type` plus its watched `trigger_column_id`, the value it waits for
 *   (`trigger_value`) and anything else it needs (`trigger_config`: a status it changes from, a
 *   date offset and time, a group, a recurring schedule).
 * - `conditions` is a list of board filter rules (`column_id`, `condition`, `value`, `values`), the
 *   same shape the toolbar's Advanced filters use, all of which must match the item (AND).
 * - `actions` is the ordered list of `{type, params}` to run. `action_type`/`action_params` always
 *   mirror the first action, see {@see self::resolvedActions()} for automations saved before
 *   actions became a list.
 *
 * Date triggers are checked by {@see RunDueDateAutomationsCommand}, recurring ones by
 * {@see RunScheduledAutomationsCommand}, every other trigger synchronously by {@see BoardAutomationService}.
 *
 * Communication actions ({@see self::ACTION_SEND_EMAIL}, {@see self::ACTION_SLACK_NOTIFY_CHANNEL},
 * {@see self::ACTION_SLACK_NOTIFY_PERSON}) reach people outside the app. They accept an optional
 * `message`, a template that may use the tokens listed on {@see BoardAutomationService::renderMessage()}.
 *
 * @property int $id
 * @property int $board_id
 * @property int $board_view_id
 * @property string|null $name
 * @property string|null $description
 * @property bool $is_enabled
 * @property string $importance
 * @property string $trigger_type
 * @property int|null $trigger_column_id
 * @property mixed $trigger_value
 * @property array<string, mixed>|null $trigger_config
 * @property array<int, array<string, mixed>>|null $conditions
 * @property string $action_type
 * @property array<string, mixed>|null $action_params
 * @property array<int, array{type: string, params: array<string, mixed>}>|null $actions
 * @property int|null $created_by_id
 * @property int|null $owner_id
 * @property Carbon|null $last_scheduled_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceNavigationItem $board
 * @property-read BoardView $boardView
 * @property-read BoardColumn|null $triggerColumn
 * @property-read User|null $creator
 * @property-read User|null $owner
 */
#[Fillable([
    'board_id', 'board_view_id', 'name', 'description', 'is_enabled', 'importance',
    'trigger_type', 'trigger_column_id', 'trigger_value', 'trigger_config', 'conditions',
    'action_type', 'action_params', 'actions', 'created_by_id', 'owner_id', 'last_scheduled_run_at',
])]
class BoardAutomation extends Model
{
    /** Fires once a `status`/`label` column changes to `trigger_value` (null for any value), optionally only from `trigger_config.from_value`. */
    public const TRIGGER_STATUS_CHANGED = 'status_changed';

    /** Fires once a `date` column's date arrives, `trigger_config.offset_days` days before (negative) or after (positive) it, at `trigger_config.time`. */
    public const TRIGGER_DATE_ARRIVED = 'date_arrived';

    /** Fires once a new root item is created on this tab, no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_ITEM_CREATED = 'item_created';

    /** Fires once a new subitem is created under any item on this tab, no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_SUBITEM_CREATED = 'subitem_created';

    /** Fires once a `people` column gains a newly-assigned person, `trigger_value` is a specific user id to watch for, or null for "anyone". */
    public const TRIGGER_PERSON_ASSIGNED = 'person_assigned';

    /** Fires once any value written to a column differs from what was stored, or only once it becomes `trigger_value` when that is set. */
    public const TRIGGER_COLUMN_CHANGED = 'column_changed';

    /** Fires once a new update (comment, not a reply) is posted on an item of this tab, no `trigger_column_id`/`trigger_value`. */
    public const TRIGGER_UPDATE_POSTED = 'update_posted';

    /** Fires once an item is moved into another group of this tab, or only into `trigger_config.group_id` when set. */
    public const TRIGGER_ITEM_MOVED_TO_GROUP = 'item_moved_to_group';

    /** Fires once an item of this tab is archived. */
    public const TRIGGER_ITEM_ARCHIVED = 'item_archived';

    /** Fires once an item of this tab is deleted. */
    public const TRIGGER_ITEM_DELETED = 'item_deleted';

    /** Fires on `trigger_config.schedule` (every day, some weekdays or one day a month, at a time), with no item of its own. */
    public const TRIGGER_RECURRING = 'recurring';

    /** Moves the item to `target_group_id`. */
    public const ACTION_MOVE_TO_GROUP = 'move_to_group';

    /** Moves the item, with its subitems, to `target_group_id` of another board `target_board_id`. */
    public const ACTION_MOVE_TO_BOARD = 'move_to_board';

    /** Notifies `notify_user_id`, or whoever `notify_from_people_column_id` currently holds, with an optional `message`. */
    public const ACTION_NOTIFY_PERSON = 'notify_person';

    /** Emails `notify_user_id`, or everyone `notify_from_people_column_id` currently holds, with an optional `subject` and `message`. */
    public const ACTION_SEND_EMAIL = 'send_email';

    /** Posts `message` to the Slack channel `slack_channel_id`. */
    public const ACTION_SLACK_NOTIFY_CHANNEL = 'slack_notify_channel';

    /** Sends `message` as a Slack direct message to the same recipients {@see self::ACTION_SEND_EMAIL} resolves. */
    public const ACTION_SLACK_NOTIFY_PERSON = 'slack_notify_person';

    /** Archives the item, it stays restorable from the board's archive. */
    public const ACTION_ARCHIVE_ITEM = 'archive_item';

    /** Deletes the item with its subitems. */
    public const ACTION_DELETE_ITEM = 'delete_item';

    /** Duplicates the item next to itself, with its subitems when `with_subitems` is true. */
    public const ACTION_DUPLICATE_ITEM = 'duplicate_item';

    /** Sets `target_column_id`'s value to `value`. Also used for "change status". */
    public const ACTION_SET_COLUMN_VALUE = 'set_column_value';

    /** Clears `target_column_id`'s value. */
    public const ACTION_CLEAR_COLUMN = 'clear_column';

    /** Adds (or with `replace` sets) a person on the people column `target_column_id`: `user_id` when `assign_mode` is `user`, the item creator for `creator`, whoever triggered it for `actor`. */
    public const ACTION_ASSIGN_PERSON = 'assign_person';

    /** Removes `user_id` from the people column `target_column_id`, or everyone when `user_id` is null. */
    public const ACTION_UNASSIGN_PEOPLE = 'unassign_people';

    /** Sets the date column `target_column_id` to today plus `offset_days`. */
    public const ACTION_SET_DATE = 'set_date';

    /** Adds `amount` (negative to subtract) to the number column `target_column_id`. */
    public const ACTION_ADJUST_NUMBER = 'adjust_number';

    /** Creates an item named `item_name` in `target_group_id`, of another board when `target_board_id` is set, copying matching values when `copy_values` is true. */
    public const ACTION_CREATE_ITEM = 'create_item';

    /** Creates one subitem under the item for every name in `subitem_names`. */
    public const ACTION_CREATE_SUBITEM = 'create_subitem';

    /** Posts `message` as an update on the item, written by the automation's owner. */
    public const ACTION_POST_UPDATE = 'post_update';

    public const IMPORTANCE_MINOR = 'minor';

    public const IMPORTANCE_MAJOR = 'major';

    public const IMPORTANCE_CRITICAL = 'critical';

    /** How many actions one automation may chain. */
    public const MAX_ACTIONS = 10;

    /** How many "and only if" conditions one automation may have. */
    public const MAX_CONDITIONS = 10;

    /**
     * @return array<int, string>
     */
    public static function triggerTypes(): array
    {
        return [
            self::TRIGGER_STATUS_CHANGED, self::TRIGGER_DATE_ARRIVED, self::TRIGGER_ITEM_CREATED,
            self::TRIGGER_SUBITEM_CREATED, self::TRIGGER_PERSON_ASSIGNED, self::TRIGGER_COLUMN_CHANGED,
            self::TRIGGER_UPDATE_POSTED, self::TRIGGER_ITEM_MOVED_TO_GROUP, self::TRIGGER_ITEM_ARCHIVED,
            self::TRIGGER_ITEM_DELETED, self::TRIGGER_RECURRING,
        ];
    }

    /**
     * Triggers that watch one column, so `trigger_column_id` is required.
     *
     * @return array<int, string>
     */
    public static function columnTriggers(): array
    {
        return [self::TRIGGER_STATUS_CHANGED, self::TRIGGER_DATE_ARRIVED, self::TRIGGER_PERSON_ASSIGNED, self::TRIGGER_COLUMN_CHANGED];
    }

    /**
     * @return array<int, string>
     */
    public static function actionTypes(): array
    {
        return [
            self::ACTION_MOVE_TO_GROUP, self::ACTION_MOVE_TO_BOARD, self::ACTION_NOTIFY_PERSON,
            self::ACTION_SEND_EMAIL, self::ACTION_SLACK_NOTIFY_CHANNEL, self::ACTION_SLACK_NOTIFY_PERSON,
            self::ACTION_ARCHIVE_ITEM, self::ACTION_DELETE_ITEM, self::ACTION_DUPLICATE_ITEM,
            self::ACTION_SET_COLUMN_VALUE, self::ACTION_CLEAR_COLUMN, self::ACTION_ASSIGN_PERSON,
            self::ACTION_UNASSIGN_PEOPLE, self::ACTION_SET_DATE, self::ACTION_ADJUST_NUMBER,
            self::ACTION_CREATE_ITEM, self::ACTION_CREATE_SUBITEM, self::ACTION_POST_UPDATE,
        ];
    }

    /**
     * Actions that work without a triggering item, the only ones a recurring automation can start
     * with. Once one of them creates an item, the actions after it run on that new item.
     *
     * @return array<int, string>
     */
    public static function itemlessActions(): array
    {
        return [self::ACTION_CREATE_ITEM, self::ACTION_NOTIFY_PERSON, self::ACTION_SEND_EMAIL, self::ACTION_SLACK_NOTIFY_CHANNEL, self::ACTION_SLACK_NOTIFY_PERSON];
    }

    /**
     * @return array<int, string>
     */
    public static function importanceLevels(): array
    {
        return [self::IMPORTANCE_MINOR, self::IMPORTANCE_MAJOR, self::IMPORTANCE_CRITICAL];
    }

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
     * The ordered actions to run. Automations saved before actions became a list only have
     * `action_type`/`action_params`, which read as a list of one.
     *
     * @return array<int, array{type: string, params: array<string, mixed>}>
     */
    public function resolvedActions(): array
    {
        if (is_array($this->actions) && $this->actions !== []) {
            return array_values(array_map(
                fn (array $action) => ['type' => (string) ($action['type'] ?? ''), 'params' => (array) ($action['params'] ?? [])],
                array_filter($this->actions, 'is_array')
            ));
        }

        return [['type' => $this->action_type, 'params' => (array) ($this->action_params ?? [])]];
    }

    /**
     * Who answers for this automation: its owner, or its creator until ownership is transferred.
     */
    public function responsibleUser(): ?User
    {
        return $this->owner ?? $this->creator;
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
     * The tab (view) this automation is scoped to, an automation only ever
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
     * The user this automation was handed over to with "Transfer ownership".
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Every recorded run of this automation, shown under the Manage tab's "Run history".
     *
     * @return HasMany<BoardAutomationRunLog, $this>
     */
    public function runLogs(): HasMany
    {
        return $this->hasMany(BoardAutomationRunLog::class, 'automation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'trigger_value' => 'array',
            'trigger_config' => 'array',
            'conditions' => 'array',
            'action_params' => 'array',
            'actions' => 'array',
            'last_scheduled_run_at' => 'datetime',
        ];
    }
}
