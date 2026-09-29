<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRun;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Support\AutomationSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the rule-based (no AI) automations a board tab defines, see {@see BoardAutomation}'s own
 * doc comment for the trigger, condition and action vocabulary. Every trigger ends up in
 * {@see executeAutomation()}, which checks the "and only if" conditions and then runs each action
 * in order through {@see BoardAutomationActionRunner}.
 *
 * - Column triggers ({@see handleValueChanged()}) are called synchronously from
 *   {@see BoardItemValueService::sync()}, the choke point every column-value write goes through.
 * - Item triggers are called from {@see BoardItemController} (created, moved, archived, deleted)
 *   and {@see BoardItemCommentController} (update posted).
 * - Date triggers ({@see runDueDateTriggers()}) and recurring ones ({@see runScheduledTriggers()})
 *   are called by scheduled commands.
 *
 * Actions can set off other automations (a status an action sets is a status change like any
 * other). Bound as a scoped singleton so the whole chain shares one instance, which stops a chain
 * deeper than {@see self::MAX_CHAIN_DEPTH} and never runs the same automation twice on the same
 * item within one chain, so two automations can never trigger each other forever.
 */
class BoardAutomationService
{
    /** How many automations may set each other off in a row before the chain is stopped. */
    public const MAX_CHAIN_DEPTH = 5;

    /** The owner hears about failures of one automation at most once in this many minutes. */
    private const FAILURE_NOTICE_MINUTES = 60;

    /** A date trigger without its own time fires from this time of day, like before times existed. */
    private const DEFAULT_DATE_TRIGGER_TIME = '08:00';

    private int $depth = 0;

    /** @var array<string, true> `automation_id:item_id` pairs running in the current chain */
    private array $running = [];

    public function __construct(
        private readonly BoardAutomationActionRunner $action_runner,
        private readonly BoardAutomationMessageRenderer $renderer,
        private readonly NotificationService $notification_service,
    ) {}

    // ── Column triggers ───────────────────────────────────────────────────────

    /**
     * Reacts to one column's value having just changed on `$item`. Status/label and
     * people columns first run their own specialised triggers, then every column type
     * runs the generic `column_changed` trigger, a no-op wherever no automation watches it.
     */
    public function handleValueChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true)) {
            $this->handleStatusChanged($item, $column, $old_value, $new_value, $actor);
        } elseif ($column->type === BoardColumn::TYPE_PEOPLE) {
            $this->handlePersonAssigned($item, $column, $old_value, $new_value, $actor);
        }

        $this->handleColumnChanged($item, $column, $old_value, $new_value, $actor);
    }

    /**
     * Fires every `column_changed` automation watching `$column` once the value it holds is
     * different from what was stored, or only once it becomes the automation's `trigger_value`.
     */
    private function handleColumnChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($this->valuesAreEqual($old_value, $new_value)) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_COLUMN_CHANGED, $column->id) as $automation) {
            if ($automation->trigger_value !== null && ! $this->valueMatches($column, $new_value, $automation->trigger_value)) {
                continue;
            }

            $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * A single-select column's value is a plain option id string. `trigger_value` null means
     * "changes to anything" (clearing the value does not count), `trigger_config.from_value`
     * narrows it to changes away from one option.
     */
    private function handleStatusChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ((string) $new_value === (string) $old_value) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_STATUS_CHANGED, $column->id) as $automation) {
            $to_value = $automation->trigger_value;
            $from_value = $automation->trigger_config['from_value'] ?? null;

            if ($to_value === null ? ($new_value === null || $new_value === '') : (string) $to_value !== (string) $new_value) {
                continue;
            }
            if ($from_value !== null && $from_value !== '' && (string) $from_value !== (string) $old_value) {
                continue;
            }

            $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * Fires once for every person newly added to a `people` column's value, a `trigger_value` of
     * null watches for anyone being assigned, a specific user id only for that person.
     */
    private function handlePersonAssigned(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $old_ids = array_map('strval', is_array($old_value) ? $old_value : []);
        $new_ids = array_map('strval', is_array($new_value) ? $new_value : []);
        $newly_added_ids = array_values(array_diff($new_ids, $old_ids));

        if (empty($newly_added_ids)) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_PERSON_ASSIGNED, $column->id) as $automation) {
            $watched_user_id = $automation->trigger_value;
            if ($watched_user_id === null || in_array((string) $watched_user_id, $newly_added_ids, true)) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
            }
        }
    }

    // ── Item triggers ─────────────────────────────────────────────────────────

    /**
     * Reacts to `$item` having just been created, fires every `item_created` (root item) or
     * `subitem_created` (has a parent) automation on the item's tab.
     */
    public function handleItemCreated(BoardItem $item, ?User $actor): void
    {
        $trigger_type = $item->parent_id === null ? BoardAutomation::TRIGGER_ITEM_CREATED : BoardAutomation::TRIGGER_SUBITEM_CREATED;

        foreach ($this->enabledAutomations($item, $trigger_type) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }
    }

    /**
     * Reacts to a top-level update having just been posted on `$item`, replies do not count.
     */
    public function handleUpdatePosted(BoardItem $item, BoardItemComment $comment, ?User $actor): void
    {
        if ($comment->parent_id !== null) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_UPDATE_POSTED) as $automation) {
            $this->executeAutomation($automation, $item, $actor, ['update_text' => (string) $comment->body]);
        }
    }

    /**
     * Reacts to a top-level item having just moved from `$from_group_id` into another group of the
     * same tab. `trigger_config.group_id` narrows an automation to one destination group.
     */
    public function handleItemMoved(BoardItem $item, int $from_group_id, ?User $actor): void
    {
        if ($item->parent_id !== null || $item->group_id === $from_group_id) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_MOVED_TO_GROUP) as $automation) {
            $group_id = $automation->trigger_config['group_id'] ?? null;
            if ($group_id !== null && (int) $group_id !== $item->group_id) {
                continue;
            }

            $this->executeAutomation($automation, $item, $actor);
        }
    }

    public function handleItemArchived(BoardItem $item, ?User $actor): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_ARCHIVED) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }
    }

    public function handleItemDeleted(BoardItem $item, ?User $actor): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_DELETED) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }
    }

    // ── Scheduled triggers ────────────────────────────────────────────────────

    /**
     * Runs every `date_arrived` automation whose date has arrived: the item's date is today
     * minus `trigger_config.offset_days` (so -2 fires two days before the date, 1 the day after it)
     * in the automation's time zone, and the configured time has passed. Without a configured time
     * a date that carries its own time (`YYYY-MM-DDTHH:mm`) fires at that time, any other date from
     * {@see self::DEFAULT_DATE_TRIGGER_TIME}. Each (automation, item) pair runs at most once a day,
     * recorded in {@see BoardAutomationRun}.
     *
     * @return int How many (automation, item) pairs ran, for the calling command to report.
     */
    public function runDueDateTriggers(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_DATE_ARRIVED)
            ->whereNotNull('trigger_column_id')
            ->with(['triggerColumn', 'board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            $config = (array) ($automation->trigger_config ?? []);
            $local_now = $now->setTimezone(AutomationSchedule::timezone($config['timezone'] ?? null));
            $today = $local_now->toDateString();
            $offset_days = (int) ($config['offset_days'] ?? 0);
            $target_date = $local_now->subDays($offset_days)->toDateString();
            $configured_time = $config['time'] ?? null;

            if ($configured_time !== null && ! $this->timeHasPassed($local_now, $configured_time)) {
                continue;
            }

            $already_ran_item_ids = BoardAutomationRun::where('automation_id', $automation->id)
                ->whereDate('ran_on', $today)
                ->pluck('board_item_id');

            $due_values = BoardItemValue::where('column_id', $automation->trigger_column_id)
                ->where(fn ($query) => DB::connection()->getDriverName() === 'mysql'
                    ? $query->whereRaw('JSON_UNQUOTE(`value`) LIKE ?', [$target_date.'%'])
                    : $query->where('value', 'like', '"'.$target_date.'%'))
                ->whereNotIn('item_id', $already_ran_item_ids)
                ->with('item.group')
                ->get();

            foreach ($due_values as $value) {
                // `BoardItem` uses `SoftDeletes`, so a soft-deleted item reads as null here.
                $item = $value->item;
                if (! $item || $item->is_archived) {
                    continue;
                }

                $own_time = is_string($value->value) && preg_match('/T(\d{2}:\d{2})/', $value->value, $matches) === 1 ? $matches[1] : null;
                $effective_time = $configured_time ?? ($offset_days === 0 ? $own_time : null) ?? self::DEFAULT_DATE_TRIGGER_TIME;
                if (! $this->timeHasPassed($local_now, $effective_time)) {
                    continue;
                }

                BoardAutomationRun::create([
                    'automation_id' => $automation->id,
                    'board_item_id' => $item->id,
                    'ran_on' => $today,
                ]);

                $this->executeAutomation($automation, $item, null, $this->changeContext($automation->triggerColumn, null, $value->value));
                $ran_count++;
            }
        }

        return $ran_count;
    }

    /**
     * Runs every `recurring` automation whose schedule came due since its last run. The time of
     * the last run is written before the actions run, so an overlapping scheduler tick can never
     * run the same occurrence twice.
     *
     * @return int How many automations ran.
     */
    public function runScheduledTriggers(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_RECURRING)
            ->with(['board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            $occurrence = AutomationSchedule::latestOccurrence((array) ($automation->trigger_config['schedule'] ?? []), $now);
            if ($occurrence === null) {
                continue;
            }

            $last_run_at = $automation->last_scheduled_run_at ?? $automation->created_at;
            if ($last_run_at !== null && $occurrence->lessThanOrEqualTo($last_run_at)) {
                continue;
            }

            $automation->forceFill(['last_scheduled_run_at' => $now])->saveQuietly();
            $this->executeAutomation($automation, null, null);
            $ran_count++;
        }

        return $ran_count;
    }

    // ── Running ───────────────────────────────────────────────────────────────

    /**
     * Checks the conditions, then runs every action of `$automation` in order and writes one run
     * history row per action. An action that throws is recorded as failed and never breaks the
     * change that triggered the automation. The owner is told about failures.
     *
     * @param  array<string, mixed>  $context  what the trigger knows (`column`, `old_value`, `new_value`, `update_text`)
     */
    private function executeAutomation(BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): void
    {
        $actions = $automation->resolvedActions();
        $first_action_type = $actions[0]['type'] ?? $automation->action_type;
        $key = $automation->id.':'.($item?->id ?? 'none');

        if ($this->depth >= self::MAX_CHAIN_DEPTH) {
            $this->recordRun($automation, $item, $actor, $first_action_type, BoardAutomationActionOutcome::skipped(
                'Stopped because too many automations triggered each other in a row.'
            ));

            return;
        }

        if (isset($this->running[$key])) {
            $this->recordRun($automation, $item, $actor, $first_action_type, BoardAutomationActionOutcome::skipped(
                'Skipped to prevent a loop, this automation already ran on this item in the same chain.'
            ));

            return;
        }

        $this->running[$key] = true;
        $this->depth++;

        try {
            if ($item !== null && ! $this->conditionsMatch($automation, $item)) {
                $this->recordRun($automation, $item, $actor, $first_action_type, BoardAutomationActionOutcome::skipped('The conditions were not met.'));

                return;
            }

            $subject = $item;
            foreach ($actions as $action) {
                try {
                    $outcome = $this->action_runner->run($automation, $action, $subject, $actor, $context);
                } catch (Throwable $exception) {
                    report($exception);
                    $outcome = BoardAutomationActionOutcome::failed('The action failed unexpectedly.');
                }

                $this->recordRun($automation, $subject ?? $outcome->created_item, $actor, $action['type'], $outcome);

                if ($outcome->status === BoardAutomationRunLog::STATUS_FAILED) {
                    $this->notifyOwnerOfFailure($automation, $subject, $outcome->message);
                }
                if ($subject === null && $outcome->created_item !== null) {
                    $subject = $outcome->created_item;
                }
                if ($outcome->stops_chain) {
                    break;
                }
            }
        } finally {
            $this->depth--;
            unset($this->running[$key]);
        }
    }

    /**
     * Whether `$item` passes every "and only if" rule, evaluated by the same engine the board's
     * Advanced filters use. A rule on a column that no longer exists is ignored.
     */
    private function conditionsMatch(BoardAutomation $automation, BoardItem $item): bool
    {
        $rules = array_values(array_filter((array) ($automation->conditions ?? []), 'is_array'));
        if ($rules === []) {
            return true;
        }

        $scope = $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        $columns = BoardColumn::where('board_view_id', $automation->board_view_id)
            ->where('scope', $scope)
            ->get()
            ->keyBy(fn (BoardColumn $column) => (string) $column->id);

        $evaluator = new BoardItemFilterEvaluator($columns, null, Carbon::today()->toDateString());

        return $evaluator->matches($item->load('values'), [
            'advanced_filter_rows' => $rules,
            'advanced_filter_operator' => 'and',
        ]);
    }

    private function recordRun(BoardAutomation $automation, ?BoardItem $item, ?User $actor, string $action_type, BoardAutomationActionOutcome $outcome): void
    {
        try {
            BoardAutomationRunLog::create([
                'automation_id' => $automation->id,
                'board_id' => $automation->board_id,
                'board_view_id' => $automation->board_view_id,
                'board_item_id' => $item?->exists && ! $item->trashed() ? $item->id : null,
                'actor_id' => $actor?->id,
                'automation_name' => $automation->name,
                'item_name' => $item ? Str::limit((string) $item->name, 250, '') : null,
                'trigger_type' => $automation->trigger_type,
                'action_type' => $action_type,
                'status' => $outcome->status,
                'message' => $outcome->message,
            ]);
        } catch (Throwable $exception) {
            // Keeping the history is best effort, it must never break the change that triggered the automation.
            report($exception);
        }
    }

    /**
     * Tells the automation's owner that a run failed, at most once an hour per automation so a
     * broken automation on a busy board does not flood their notifications.
     */
    private function notifyOwnerOfFailure(BoardAutomation $automation, ?BoardItem $item, string $message): void
    {
        $owner = $automation->responsibleUser();
        if (! $owner || ! $owner->is_active) {
            return;
        }
        if (! Cache::add("automation_failure_notice:{$automation->id}", true, now()->addMinutes(self::FAILURE_NOTICE_MINUTES))) {
            return;
        }

        try {
            $this->notification_service->notify(
                recipient: $owner,
                actor: null,
                type: Notification::TYPE_AUTOMATION,
                board: $automation->board,
                action_label: 'Automation "'.($automation->name ?: 'Automation').'" failed',
                action_target: $message,
                link: $item && ! $item->trashed() ? "/boards/{$item->board_id}/pulses/{$item->id}" : "/boards/{$automation->board_id}",
                board_item: $item && ! $item->trashed() ? $item : null,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return EloquentCollection<int, BoardAutomation>
     */
    private function enabledAutomations(BoardItem $item, string $trigger_type, ?int $column_id = null): EloquentCollection
    {
        $view_id = $item->group?->board_view_id;
        if ($view_id === null) {
            return new EloquentCollection;
        }

        return BoardAutomation::query()
            ->where('board_view_id', $view_id)
            ->where('is_enabled', true)
            ->where('trigger_type', $trigger_type)
            ->when($column_id !== null, fn ($query) => $query->where('trigger_column_id', $column_id))
            ->with(['board', 'creator', 'owner'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function changeContext(?BoardColumn $column, mixed $old_value, mixed $new_value): array
    {
        return ['column' => $column, 'old_value' => $old_value, 'new_value' => $new_value];
    }

    private function timeHasPassed(CarbonImmutable $local_now, string $time): bool
    {
        [$hour, $minute] = AutomationSchedule::parseTime($time);

        return $local_now->greaterThanOrEqualTo($local_now->setTime($hour, $minute));
    }

    /**
     * Whether a column's new value is the one a `column_changed` automation waits for, read the
     * way each column type stores its value.
     */
    public function valueMatches(BoardColumn $column, mixed $value, mixed $expected): bool
    {
        if ($column->type === BoardColumn::TYPE_CHECKBOX) {
            $is_checked = in_array($value, [true, 1, '1', 'true'], true);

            return $is_checked === in_array($expected, [true, 1, '1', 'true', 'checked'], true);
        }

        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        if (is_array($value)) {
            $held = array_map('strval', array_filter($value, 'is_scalar'));
            $wanted = array_map('strval', array_filter((array) $expected, 'is_scalar'));

            return array_intersect($held, $wanted) !== [];
        }

        if (in_array($column->type, [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS], true)) {
            return is_numeric($value) && is_numeric($expected) && (float) $value === (float) $expected;
        }

        if ($column->type === BoardColumn::TYPE_DATE) {
            return substr((string) $value, 0, 10) === substr((string) $expected, 0, 10);
        }

        return mb_strtolower(trim((string) $value)) === mb_strtolower(trim(is_scalar($expected) ? (string) $expected : ''));
    }

    /**
     * Treats null, an empty string and an empty array as the same "no value".
     */
    public function valuesAreEqual(mixed $old_value, mixed $new_value): bool
    {
        $normalize = fn (mixed $value) => ($value === null || $value === '' || $value === []) ? null : $value;

        return json_encode($normalize($old_value)) === json_encode($normalize($new_value));
    }

    /**
     * Fills in the first action's `message` template, see {@see BoardAutomationMessageRenderer}.
     *
     * @param  array<string, mixed>  $context
     */
    public function renderMessage(BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): string
    {
        $message = $automation->resolvedActions()[0]['params']['message'] ?? null;

        return $this->renderer->render(is_string($message) ? $message : null, $automation, $item, $actor, $context);
    }
}
