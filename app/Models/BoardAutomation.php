<?php

namespace App\Models;

use App\Console\Commands\Board\RunDueDateAutomationsCommand;
use App\Console\Commands\Board\RunScheduledAutomationsCommand;
use App\Services\Board\AutomationDynamicValueResolver;
use App\Services\Board\BoardAutomationService;
use App\Services\ExternalAccounts\EmailTriggerPoller;
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
 * @property int $consecutive_failures
 * @property Carbon|null $last_failed_at
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

    /** Fires once someone is removed from a `people` column, `trigger_value` is a specific user id to watch for, or null for "anyone". */
    public const TRIGGER_PERSON_UNASSIGNED = 'person_unassigned';

    /** Fires once a file or link is added to a `files` column, only files whose extension is in `trigger_config.extensions` when set. */
    public const TRIGGER_FILE_UPLOADED = 'file_uploaded';

    /**
     * Fires once the date (or the end of the timeline) `trigger_column_id` has passed while the item is not done: the status
     * column `trigger_config.status_column_id` does not hold one of `trigger_config.done_values`. Checked on a schedule, at
     * `trigger_config.time`, once per item and date. Items already overdue when the automation is created are left alone.
     */
    public const TRIGGER_ITEM_OVERDUE = 'item_overdue';

    /**
     * Fires once a subitem column (`trigger_column_id`, subitem scope) changes on any subitem, or only once the new value
     * passes `trigger_config.match` (the same per type match `column_changed` uses). The actions run on the parent item
     * when `trigger_config.run_on` is `parent` (the default) and on the subitem itself when it is `subitem`.
     */
    public const TRIGGER_SUBITEM_COLUMN_CHANGED = 'subitem_column_changed';

    /** Fires once for every person `@mentioned` in an update or reply, only for the user id `trigger_value` when set. */
    public const TRIGGER_USER_MENTIONED = 'user_mentioned';

    /** Fires once someone replies to an update of an item, only replies written by the user id `trigger_value` when set. */
    public const TRIGGER_UPDATE_REPLIED = 'update_replied';

    /**
     * Fires once an update (and a reply, with `trigger_config.include_replies`) contains one of `trigger_config.keywords`,
     * matched without case anywhere in its text.
     */
    public const TRIGGER_UPDATE_KEYWORD = 'update_keyword';

    /**
     * Fires once the status (or label) column `trigger_column_id` has held the same label, `trigger_value` or any label
     * when null, for `trigger_config.amount` `trigger_config.unit`s (`hours` or `days`). Checked on a schedule, once per
     * item and stretch: the label has to change and get stuck again to fire again.
     */
    public const TRIGGER_STATUS_STUCK = 'status_stuck';

    /**
     * Fires once an item has not been updated, no column value, name or update posted, for `trigger_config.amount`
     * `trigger_config.unit`s (`hours` or `days`), only items of `trigger_config.group_id` when set. Checked on a schedule,
     * once per item and quiet stretch.
     */
    public const TRIGGER_ITEM_STALE = 'item_stale';

    /**
     * Fires once for every new email in the inbox of the connected Gmail or Outlook account
     * `trigger_config.external_account_id`, with no item of its own. Only emails whose sender holds
     * `trigger_config.from_filter` and whose subject holds `trigger_config.subject_filter` count when
     * those are set. The inbox is polled every minute, see {@see EmailTriggerPoller}, and the actions
     * read the email through `{payload.subject}`, `{payload.body}`, `{payload.from_name}` and `{payload.from_email}`.
     */
    public const TRIGGER_EMAIL_RECEIVED = 'email_received';

    /** Fires once a root item of this tab is created, renamed or has any column value changed. */
    public const TRIGGER_ITEM_CREATED_OR_UPDATED = 'item_created_or_updated';

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

    /** Renames the item to `name_template`, tokens such as `{item_name}` and `{column:12}` filled in. */
    public const ACTION_RENAME_ITEM = 'rename_item';

    /**
     * Adds (`mode` `add`) or removes (`remove`) the `values` of a `dropdown`, `tags`, `people` or `vote` column
     * `target_column_id`, keeping the rest. For people and votes `__actor__` and `__creator__` stand for whoever set the
     * automation off and the item creator.
     */
    public const ACTION_CHANGE_VALUES = 'change_values';

    /** Sets `linked_column_id`, a column of the connected board, to `value` on every item the connect boards column `connect_column_id` links to. */
    public const ACTION_UPDATE_CONNECTED_ITEMS = 'update_connected_items';

    /**
     * Runs `operation` (`set_column_value`, `clear_column`, `archive` or `move_to_group`) on every item of the group
     * `target_group_id`, or of the item's own group with `from_item_group`: `target_column_id` and `value` for the column
     * operations, `destination_group_id` for a move.
     */
    public const ACTION_GROUP_ITEMS = 'group_items';

    /**
     * Subscribes people to the item, so its updates show in their Update Feed "Following" tab: the fixed `user_ids`,
     * whoever the people column `notify_from_people_column_id` holds, every member of `team_id`, and the person
     * `recipient_source` names (`actor`, `creator`, `owner` or `mentioned`).
     */
    public const ACTION_SUBSCRIBE_PEOPLE = 'subscribe_people';

    /** Unsubscribes the same people {@see self::ACTION_SUBSCRIBE_PEOPLE} resolves from the item, or everyone with `everyone`. */
    public const ACTION_UNSUBSCRIBE_PEOPLE = 'unsubscribe_people';

    /** Notifies everyone subscribed to the item with an optional `message`, leaving out whoever set the automation off unless `include_actor`. */
    public const ACTION_NOTIFY_SUBSCRIBERS = 'notify_subscribers';

    /** Archives (`operation` `archive`) or deletes (`delete`) every subitem of the item. */
    public const ACTION_CLEAR_SUBITEMS = 'clear_subitems';

    /**
     * Turns a subitem into an item of its parent's group (or of `target_group_id`), copying the values of subitem columns
     * into the item columns with the same label and type.
     */
    public const ACTION_CONVERT_SUBITEM = 'convert_subitem';

    /**
     * Emails a table of the tab's items that pass `digest_rules` (board filter rules combined with `digest_operator`),
     * only of `target_group_id` when set, showing `column_ids`, to `user_ids` and the members of `team_id`. At most
     * `max_items` rows, nothing is sent when no item matches unless `send_when_empty`. Works without an item, so a
     * recurring trigger can send it every week.
     */
    public const ACTION_SEND_DIGEST = 'send_digest';

    /** Moves the item to the `position` `top` or `bottom` of its group, a subitem among its parent's subitems. */
    public const ACTION_MOVE_ITEM_POSITION = 'move_item_position';

    /**
     * Sorts the items of `target_group_id`, or of the item's own group with `from_item_group`, by `sort_by` (a column id,
     * `name` or `__created_at__`) in the `direction` `asc` or `desc`. Empty values always go last.
     */
    public const ACTION_SORT_GROUP = 'sort_group';

    /**
     * Creates, or updates when it already exists, an event for the item in the Google Calendar `calendar_id` of the
     * connected account `external_account_id`, on the date (or timeline) `date_column_id`, titled `title_template`
     * (tokens such as `{item_name}` filled in, the item name when empty). An item that loses its date, is archived or
     * is deleted loses its event, see {@see BoardItemCalendarEvent}.
     */
    public const ACTION_GOOGLE_CALENDAR_SYNC = 'google_calendar_sync';

    /** Where a dynamic value comes from, see {@see AutomationDynamicValueResolver}. */
    public const DYNAMIC_SOURCES = ['actor', 'creator', 'owner', 'mentioned', 'today', 'column'];

    /** The people a recipient token can stand for, besides fixed people and people columns. */
    public const RECIPIENT_SOURCES = ['actor', 'creator', 'owner', 'mentioned', 'subscribers'];

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
            self::TRIGGER_PERSON_UNASSIGNED, self::TRIGGER_FILE_UPLOADED, self::TRIGGER_ITEM_OVERDUE,
            self::TRIGGER_SUBITEM_COLUMN_CHANGED, self::TRIGGER_USER_MENTIONED, self::TRIGGER_UPDATE_REPLIED,
            self::TRIGGER_UPDATE_KEYWORD, self::TRIGGER_STATUS_STUCK, self::TRIGGER_ITEM_STALE,
            self::TRIGGER_EMAIL_RECEIVED, self::TRIGGER_ITEM_CREATED_OR_UPDATED,
        ];
    }

    /** The longest quiet period a "stuck" or "not updated" trigger may wait, in days. */
    public const MAX_QUIET_DAYS = 365;

    /**
     * How long a "stuck" or "not updated" trigger waits, in minutes, null when it is not one or its
     * `trigger_config.amount` is missing.
     */
    public function quietPeriodMinutes(): ?int
    {
        if (! in_array($this->trigger_type, self::quietTriggers(), true)) {
            return null;
        }

        $amount = (int) ($this->trigger_config['amount'] ?? 0);
        if ($amount < 1) {
            return null;
        }

        return ($this->trigger_config['unit'] ?? 'days') === 'hours' ? $amount * 60 : $amount * 1440;
    }

    /**
     * Where a `subitem_column_changed` automation runs its actions: `parent` (default) or `subitem`.
     */
    public function runsOnSubitem(): bool
    {
        return $this->trigger_type === self::TRIGGER_SUBITEM_COLUMN_CHANGED && ($this->trigger_config['run_on'] ?? 'parent') === 'subitem';
    }

    /**
     * Triggers with no item of their own, so their first action must be one of {@see self::itemlessActions()}.
     *
     * @return array<int, string>
     */
    public static function itemlessTriggers(): array
    {
        return [self::TRIGGER_RECURRING, self::TRIGGER_WEBHOOK_RECEIVED, self::TRIGGER_EMAIL_RECEIVED];
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
            self::TRIGGER_CHECKLIST_ITEM_CHECKED, self::TRIGGER_PERSON_UNASSIGNED, self::TRIGGER_FILE_UPLOADED,
            self::TRIGGER_ITEM_OVERDUE, self::TRIGGER_SUBITEM_COLUMN_CHANGED, self::TRIGGER_STATUS_STUCK,
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
            self::TRIGGER_STATUS_CHANGED, self::TRIGGER_ALL_SUBITEMS_STATUS, self::TRIGGER_ALL_GROUP_ITEMS_STATUS, self::TRIGGER_STATUS_STUCK => [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL],
            self::TRIGGER_DATE_ARRIVED => [BoardColumn::TYPE_DATE],
            self::TRIGGER_DATE_CHANGED => [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE],
            self::TRIGGER_PERSON_ASSIGNED, self::TRIGGER_PERSON_UNASSIGNED => [BoardColumn::TYPE_PEOPLE],
            self::TRIGGER_FILE_UPLOADED => [BoardColumn::TYPE_FILES],
            self::TRIGGER_ITEM_OVERDUE => [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE],
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
            self::ACTION_RENAME_ITEM, self::ACTION_CHANGE_VALUES, self::ACTION_UPDATE_CONNECTED_ITEMS, self::ACTION_GROUP_ITEMS,
            self::ACTION_SUBSCRIBE_PEOPLE, self::ACTION_UNSUBSCRIBE_PEOPLE, self::ACTION_NOTIFY_SUBSCRIBERS,
            self::ACTION_CLEAR_SUBITEMS, self::ACTION_CONVERT_SUBITEM, self::ACTION_SEND_DIGEST,
            self::ACTION_MOVE_ITEM_POSITION, self::ACTION_SORT_GROUP, self::ACTION_GOOGLE_CALENDAR_SYNC,
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
            self::ACTION_NOTIFY_TEAM, self::ACTION_SEND_WEBHOOK, self::ACTION_WAIT, self::ACTION_GROUP_ITEMS,
            self::ACTION_SEND_DIGEST, self::ACTION_SORT_GROUP,
        ];
    }

    /**
     * Triggers checked by the scheduler that watch how long nothing changed, see
     * {@see BoardAutomationService::runStuckTriggers()}.
     *
     * @return array<int, string>
     */
    public static function quietTriggers(): array
    {
        return [self::TRIGGER_STATUS_STUCK, self::TRIGGER_ITEM_STALE];
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
     * The member Slack connections this automation's channel posts go through, from both branches.
     *
     * @return array<int, int>
     */
    public function slackConnectionIds(): array
    {
        $actions = [...($this->actions ?? []), ...($this->else_actions ?? []), ['params' => $this->action_params ?? []]];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $action) => is_array($action) && is_numeric($action['params']['slack_connection_id'] ?? null) ? (int) $action['params']['slack_connection_id'] : null,
            $actions,
        ))));
    }

    /**
     * The member Google and Microsoft accounts this automation reads or sends through: the inbox of
     * an email trigger and the account of every email or calendar action, from both branches.
     *
     * @return array<int, int>
     */
    public function externalAccountIds(): array
    {
        $actions = [...($this->actions ?? []), ...($this->else_actions ?? []), ['params' => $this->action_params ?? []]];
        $ids = array_map(
            fn (mixed $action) => is_array($action) && is_numeric($action['params']['external_account_id'] ?? null) ? (int) $action['params']['external_account_id'] : null,
            $actions,
        );
        $ids[] = is_numeric($this->trigger_config['external_account_id'] ?? null) ? (int) $this->trigger_config['external_account_id'] : null;

        return array_values(array_unique(array_filter($ids)));
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
            'consecutive_failures' => 'integer',
            'last_failed_at' => 'datetime',
            'last_scheduled_run_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }
}
