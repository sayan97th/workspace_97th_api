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
 *   same shape the toolbar's Advanced filters use, combined with `condition_operator` (`and`/`or`)
 *   together with the rule groups of `condition_groups` (`[{join_operator, rules}]`).
 * - `else_actions` is the "Otherwise" branch, run when an item does not pass the conditions.
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
 * @property string $condition_operator
 * @property array<int, array{join_operator?: string, rules?: array<int, array<string, mixed>>}>|null $condition_groups
 * @property array<int, array{type: string, params: array<string, mixed>}>|null $else_actions
 * @property string $failure_alert
 * @property array<string, mixed>|null $state
 * @property string $action_type
 * @property array<string, mixed>|null $action_params
 * @property array<int, array{type: string, params: array<string, mixed>}>|null $actions
 * @property int|null $created_by_id
 * @property int|null $owner_id
 * @property Carbon|null $last_scheduled_run_at
 * @property string|null $webhook_token
 * @property Carbon|null $paused_at
 * @property string|null $paused_reason
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
    'webhook_token', 'paused_at', 'paused_reason', 'condition_operator', 'condition_groups', 'else_actions',
    'failure_alert', 'state',
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

    /** Fires on the parent item once a subitem `status`/`label` column (`trigger_column_id`) holds `trigger_value` on every one of its subitems. */
    public const TRIGGER_ALL_SUBITEMS_STATUS = 'all_subitems_status';

    /** Fires once a `status`/`label` column holds `trigger_value` on every item of a group, or only of `trigger_config.group_id`. */
    public const TRIGGER_ALL_GROUP_ITEMS_STATUS = 'all_group_items_status';

    /** On `trigger_config.schedule`, runs the actions once for every item of the tab that passes the `conditions`. */
    public const TRIGGER_ITEM_SCAN = 'item_scan';

    /** Fires once a board form creates an item on this tab, or only the form `trigger_config.form_view_id`. */
    public const TRIGGER_FORM_SUBMITTED = 'form_submitted';

    /** Fires once an item of this tab is renamed. */
    public const TRIGGER_NAME_CHANGED = 'name_changed';

    /** Fires once a `date`/`timeline` column's value changes, set, moved or cleared. */
    public const TRIGGER_DATE_CHANGED = 'date_changed';

    /** Fires once a JSON body is posted to the automation's public webhook URL, with no item of its own. */
    public const TRIGGER_WEBHOOK_RECEIVED = 'webhook_received';

    /** Fires once someone presses the button column `trigger_column_id` on an item. */
    public const TRIGGER_BUTTON_CLICKED = 'button_clicked';

    /** Fires once a number column crosses `trigger_config.threshold` (`operator` `above`, `below` or `equals`), it did not hold before the change. */
    public const TRIGGER_NUMBER_THRESHOLD = 'number_threshold';

    /** Fires once an item is moved onto this tab from another board, or only from `trigger_config.from_board_id`. */
    public const TRIGGER_ITEM_MOVED_TO_BOARD = 'item_moved_to_board';

    /** Fires once an archived or deleted item of this tab is restored. */
    public const TRIGGER_ITEM_RESTORED = 'item_restored';

    /** Fires once every task of the checklist column `trigger_column_id` is checked, it was not complete before. */
    public const TRIGGER_CHECKLIST_COMPLETED = 'checklist_completed';

    /** Fires once a task of the checklist column is checked, only the task named `trigger_value` when set. */
    public const TRIGGER_CHECKLIST_ITEM_CHECKED = 'checklist_item_checked';

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

    /** Moves the date (or both ends of the timeline) `target_column_id` by `amount` `unit`s (`days`, `weeks`, `months`), negative to move it earlier. */
    public const ACTION_SHIFT_DATE = 'shift_date';

    /** Sets the date `target_column_id` to the date in `source_column_id` plus `offset_days`, plus (or minus, `number_sign` -1) the days in `number_column_id` when set. */
    public const ACTION_SET_DATE_FROM_COLUMN = 'set_date_from_column';

    /** Moves `target_column_id` (date or timeline) to start `gap_days` after `source_column_id` ends, only when it starts earlier than that. */
    public const ACTION_ENSURE_DATE_AFTER = 'ensure_date_after';

    /** Sets the timeline `target_column_id` to start `start_offset_days` from today and last `duration_days`. */
    public const ACTION_SET_TIMELINE = 'set_timeline';

    /** Creates a group named `group_name` (tokens filled in) at the `position` `top` or `bottom` of the tab. */
    public const ACTION_CREATE_GROUP = 'create_group';

    /** Duplicates `source_group_id`, or the item's own group when `from_item_group`, named `group_name`, with its items when `with_items`. */
    public const ACTION_DUPLICATE_GROUP = 'duplicate_group';

    /** Archives `target_group_id`, or the item's own group when `from_item_group`. */
    public const ACTION_ARCHIVE_GROUP = 'archive_group';

    /** Copies the value of `source_column_id` into `target_column_id`, converted to text when the target is a text column. */
    public const ACTION_COPY_COLUMN_VALUE = 'copy_column_value';

    /** Starts or stops (`mode`) the timer of the time tracking column `target_column_id`. */
    public const ACTION_TIME_TRACKING = 'time_tracking';

    /** Links the item, in the connect boards column `target_column_id`, to every item of the connected board whose `linked_match_column_id` matches this item's `match_column_id` (`name` for the item name). */
    public const ACTION_CONNECT_ITEMS = 'connect_items';

    /** Notifies every active member of the team `team_id`, with an optional `message`. */
    public const ACTION_NOTIFY_TEAM = 'notify_team';

    /** Posts the item, the trigger and the board as JSON to `url`, signed with `secret` when one is set. */
    public const ACTION_SEND_WEBHOOK = 'send_webhook';

    /** Waits `amount` `unit`s (`minutes`, `hours`, `days`) before the next actions, optionally checking the conditions again (`recheck_conditions`). */
    public const ACTION_WAIT = 'wait';

    /** Moves the date or timeline `target_column_id` of every item whose dependency column `dependency_column_id` points at this item, `mode` `strict` (keep the gap) or `flexible` (only when they would overlap). */
    public const ACTION_SHIFT_DEPENDENTS = 'shift_dependents';

    /** Assigns the next person of `user_ids` in the people column `target_column_id`, `strategy` `rotation` (in turn) or `least_busy` (fewest open items). */
    public const ACTION_ASSIGN_ROUND_ROBIN = 'assign_round_robin';

    /** Sets the subitem column `target_column_id` to `value` on every subitem of the item. */
    public const ACTION_SET_SUBITEMS_VALUE = 'set_subitems_value';

    /** Sets the item column `target_column_id` of a subitem's parent to `value`. */
    public const ACTION_SET_PARENT_VALUE = 'set_parent_value';

    /** Adds one task per entry of `tasks` to the checklist column `target_column_id`, skipping tasks it already has. */
    public const ACTION_ADD_CHECKLIST_ITEMS = 'add_checklist_items';

    public const FAILURE_ALERT_APP = 'app';

    public const FAILURE_ALERT_APP_AND_EMAIL = 'app_and_email';

    public const FAILURE_ALERT_NONE = 'none';

    public const IMPORTANCE_MINOR = 'minor';

    public const IMPORTANCE_MAJOR = 'major';

    public const IMPORTANCE_CRITICAL = 'critical';

    /** How many actions one automation may chain. */
    public const MAX_ACTIONS = 10;

    /** How many "and only if" conditions one automation may have, counted over its groups too. */
    public const MAX_CONDITIONS = 20;

    /** How many condition groups one automation may have. */
    public const MAX_CONDITION_GROUPS = 5;

    /** The longest a "wait" step may wait, in days. */
    public const MAX_WAIT_DAYS = 30;

    /**
     * @return array<int, string>
     */
    public static function triggerTypes(): array
    {
        return [
            self::TRIGGER_STATUS_CHANGED, self::TRIGGER_DATE_ARRIVED, self::TRIGGER_ITEM_CREATED,
            self::TRIGGER_SUBITEM_CREATED, self::TRIGGER_PERSON_ASSIGNED, self::TRIGGER_COLUMN_CHANGED,
            self::TRIGGER_UPDATE_POSTED, self::TRIGGER_ITEM_MOVED_TO_GROUP, self::TRIGGER_ITEM_ARCHIVED,
            self::TRIGGER_ITEM_DELETED, self::TRIGGER_RECURRING, self::TRIGGER_ALL_SUBITEMS_STATUS,
            self::TRIGGER_ALL_GROUP_ITEMS_STATUS, self::TRIGGER_ITEM_SCAN, self::TRIGGER_FORM_SUBMITTED,
            self::TRIGGER_NAME_CHANGED, self::TRIGGER_DATE_CHANGED, self::TRIGGER_WEBHOOK_RECEIVED,
            self::TRIGGER_BUTTON_CLICKED, self::TRIGGER_NUMBER_THRESHOLD, self::TRIGGER_ITEM_MOVED_TO_BOARD,
            self::TRIGGER_ITEM_RESTORED, self::TRIGGER_CHECKLIST_COMPLETED, self::TRIGGER_CHECKLIST_ITEM_CHECKED,
        ];
    }

    /**
     * Triggers with no item of their own, so their first action must be one of {@see self::itemlessActions()}.
     *
     * @return array<int, string>
     */
    public static function itemlessTriggers(): array
    {
        return [self::TRIGGER_RECURRING, self::TRIGGER_WEBHOOK_RECEIVED];
    }

    /**
     * Triggers that run on a schedule, `trigger_config.schedule` is required.
     *
     * @return array<int, string>
     */
    public static function scheduledTriggers(): array
    {
        return [self::TRIGGER_RECURRING, self::TRIGGER_ITEM_SCAN];
    }

    /**
     * Triggers that watch one column, so `trigger_column_id` is required.
     *
     * @return array<int, string>
     */
    public static function columnTriggers(): array
    {
        return [
            self::TRIGGER_STATUS_CHANGED, self::TRIGGER_DATE_ARRIVED, self::TRIGGER_PERSON_ASSIGNED, self::TRIGGER_COLUMN_CHANGED,
            self::TRIGGER_ALL_SUBITEMS_STATUS, self::TRIGGER_ALL_GROUP_ITEMS_STATUS, self::TRIGGER_DATE_CHANGED,
            self::TRIGGER_BUTTON_CLICKED, self::TRIGGER_NUMBER_THRESHOLD, self::TRIGGER_CHECKLIST_COMPLETED,
            self::TRIGGER_CHECKLIST_ITEM_CHECKED,
        ];
    }

    /**
     * The column types a column trigger accepts, null when any writable column will do.
     *
     * @return array<int, string>|null
     */
    public static function triggerColumnTypes(string $trigger_type): ?array
    {
        return match ($trigger_type) {
            self::TRIGGER_STATUS_CHANGED, self::TRIGGER_ALL_SUBITEMS_STATUS, self::TRIGGER_ALL_GROUP_ITEMS_STATUS => [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL],
            self::TRIGGER_DATE_ARRIVED => [BoardColumn::TYPE_DATE],
            self::TRIGGER_DATE_CHANGED => [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE],
            self::TRIGGER_PERSON_ASSIGNED => [BoardColumn::TYPE_PEOPLE],
            self::TRIGGER_BUTTON_CLICKED => [BoardColumn::TYPE_BUTTON],
            self::TRIGGER_NUMBER_THRESHOLD => [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_TIME_TRACKING],
            self::TRIGGER_CHECKLIST_COMPLETED, self::TRIGGER_CHECKLIST_ITEM_CHECKED => [BoardColumn::TYPE_CHECKLIST],
            default => null,
        };
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
            self::ACTION_SHIFT_DATE, self::ACTION_SET_DATE_FROM_COLUMN, self::ACTION_ENSURE_DATE_AFTER,
            self::ACTION_SET_TIMELINE, self::ACTION_CREATE_GROUP, self::ACTION_DUPLICATE_GROUP,
            self::ACTION_ARCHIVE_GROUP, self::ACTION_COPY_COLUMN_VALUE, self::ACTION_TIME_TRACKING,
            self::ACTION_CONNECT_ITEMS, self::ACTION_NOTIFY_TEAM, self::ACTION_SEND_WEBHOOK,
            self::ACTION_WAIT, self::ACTION_SHIFT_DEPENDENTS, self::ACTION_ASSIGN_ROUND_ROBIN,
            self::ACTION_SET_SUBITEMS_VALUE, self::ACTION_SET_PARENT_VALUE, self::ACTION_ADD_CHECKLIST_ITEMS,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function failureAlerts(): array
    {
        return [self::FAILURE_ALERT_APP, self::FAILURE_ALERT_APP_AND_EMAIL, self::FAILURE_ALERT_NONE];
    }

    /**
     * Actions that work without a triggering item, the only ones a recurring automation can start
     * with. Once one of them creates an item, the actions after it run on that new item.
     *
     * @return array<int, string>
     */
    public static function itemlessActions(): array
    {
        return [
            self::ACTION_CREATE_ITEM, self::ACTION_NOTIFY_PERSON, self::ACTION_SEND_EMAIL, self::ACTION_SLACK_NOTIFY_CHANNEL,
            self::ACTION_SLACK_NOTIFY_PERSON, self::ACTION_CREATE_GROUP, self::ACTION_DUPLICATE_GROUP, self::ACTION_ARCHIVE_GROUP,
            self::ACTION_NOTIFY_TEAM, self::ACTION_SEND_WEBHOOK, self::ACTION_WAIT,
        ];
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
     * The "Otherwise" actions, empty when the automation has no else branch.
     *
     * @return array<int, array{type: string, params: array<string, mixed>}>
     */
    public function resolvedElseActions(): array
    {
        return array_values(array_map(
            fn (array $action) => ['type' => (string) ($action['type'] ?? ''), 'params' => (array) ($action['params'] ?? [])],
            array_filter((array) ($this->else_actions ?? []), 'is_array')
        ));
    }

    /**
     * The actions of one branch, `then` or `else`.
     *
     * @return array<int, array{type: string, params: array<string, mixed>}>
     */
    public function branchActions(string $branch): array
    {
        return $branch === 'else' ? $this->resolvedElseActions() : $this->resolvedActions();
    }

    /**
     * The conditions as the board filter engine reads them: the top-level rules, the groups and
     * how they combine.
     *
     * @return array{advanced_filter_rows: array<int, array<string, mixed>>, advanced_filter_groups: array<int, array<string, mixed>>, advanced_filter_operator: string}
     */
    public function conditionFilterState(): array
    {
        return [
            'advanced_filter_rows' => array_values(array_filter((array) ($this->conditions ?? []), 'is_array')),
            'advanced_filter_groups' => array_values(array_filter((array) ($this->condition_groups ?? []), 'is_array')),
            'advanced_filter_operator' => $this->condition_operator === 'or' ? 'or' : 'and',
        ];
    }

    /**
     * Whether the automation has any "and only if" rule, in a group or not.
     */
    public function hasConditions(): bool
    {
        $state = $this->conditionFilterState();
        $grouped = collect($state['advanced_filter_groups'])->sum(fn (array $group) => count((array) ($group['rules'] ?? [])));

        return count($state['advanced_filter_rows']) + $grouped > 0;
    }

    /**
     * The public URL a "When a webhook is received" automation listens on, null for every other trigger.
     */
    public function webhookUrl(): ?string
    {
        if ($this->trigger_type !== self::TRIGGER_WEBHOOK_RECEIVED || ! $this->webhook_token) {
            return null;
        }

        return url("/api/public/automation-webhooks/{$this->webhook_token}");
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
     * Every saved version of this automation, newest first.
     *
     * @return HasMany<BoardAutomationVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BoardAutomationVersion::class, 'automation_id')->orderByDesc('version');
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
            'condition_groups' => 'array',
            'else_actions' => 'array',
            'state' => 'array',
            'last_scheduled_run_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }
}
