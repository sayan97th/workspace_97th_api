<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardItemCommentController;
use App\Http\Controllers\Board\BoardItemController;
use App\Jobs\SendEmailJob;
use App\Mail\Automations\AutomationEmail;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationDelayedRun;
use App\Models\BoardAutomationRun;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\Notification;
use App\Models\User;
use App\Services\ExternalAccounts\CalendarEventSyncer;
use App\Services\Notification\NotificationService;
use App\Support\AutomationSchedule;
use App\Support\MarkdownPlainText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * An automation that uses a column, group, board, person or team that no longer exists is paused
 * before it runs (see {@see BoardAutomationHealthChecker}) and its owner is told why. Nothing runs
 * while the board's "Pause all automations" is on ({@see BoardAutomationSetting}).
 *
 * An item that does not pass the conditions runs the "Otherwise" actions when there are any. A
 * "wait" action stores the rest of its branch as a {@see BoardAutomationDelayedRun}, continued by
 * {@see runDelayedRuns()}. Every step of one execution shares a `run_uuid` in the run history, and
 * a failed step can be run again with {@see retryRun()}.
 * {@see testRun()} runs an automation on one item inside a transaction it rolls back, and reports
 * what each condition and action would have done.
 */
class BoardAutomationService
{
    /** How many automations may set each other off in a row before the chain is stopped. */
    public const MAX_CHAIN_DEPTH = 5;

    /** The owner hears about failures of one automation at most once in this many minutes. */
    private const FAILURE_NOTICE_MINUTES = 60;

    /** A date trigger without its own time fires from this time of day, like before times existed. */
    private const DEFAULT_DATE_TRIGGER_TIME = '08:00';

    /** Most items one scheduled item scan acts on per run, the rest wait for the next run. */
    public const MAX_SCAN_ITEMS = 500;

    private int $depth = 0;

    /** @var array<string, true> `automation_id:item_id` pairs running in the current chain */
    private array $running = [];

    /**
     * What a test run did, one entry per action of every automation that ran, in order.
     *
     * @var array<int, array{automation_name: string, is_chained: bool, action_type: string, status: string, message: string}>
     */
    private array $test_outcomes = [];

    private ?BoardAutomation $test_root = null;

    /** Set while {@see retryRun()} runs, every run log written meanwhile points back at the failed one. */
    private ?int $retry_of_id = null;

    /** @var array<string, string> run id => `failed` once a step of that run failed, `success` once one succeeded */
    private array $run_outcomes = [];

    public function __construct(
        private readonly BoardAutomationActionRunner $action_runner,
        private readonly BoardAutomationMessageRenderer $renderer,
        private readonly NotificationService $notification_service,
        private readonly AutomationRunContext $run_context,
        private readonly BoardAutomationHealthChecker $health_checker,
        private readonly AutomationConditionEvaluator $condition_evaluator,
        private readonly AutomationUsageMeter $usage_meter,
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
            $this->handleAllSubitemsStatus($item, $column, $old_value, $new_value, $actor);
            $this->handleAllGroupItemsStatus($item, $column, $old_value, $new_value, $actor);
        } elseif ($column->type === BoardColumn::TYPE_PEOPLE) {
            $this->handlePersonAssigned($item, $column, $old_value, $new_value, $actor);
            $this->handlePersonUnassigned($item, $column, $old_value, $new_value, $actor);
        } elseif ($column->type === BoardColumn::TYPE_FILES) {
            $this->handleFilesAdded($item, $column, $old_value, $new_value, $actor);
        } elseif (in_array($column->type, [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE], true)) {
            $this->handleDateChanged($item, $column, $old_value, $new_value, $actor);
        } elseif (in_array($column->type, BoardAutomation::triggerColumnTypes(BoardAutomation::TRIGGER_NUMBER_THRESHOLD) ?? [], true)) {
            $this->handleNumberThreshold($item, $column, $old_value, $new_value, $actor);
        } elseif ($column->type === BoardColumn::TYPE_CHECKLIST) {
            $this->handleChecklistChanged($item, $column, $old_value, $new_value, $actor);
        }

        $this->handleColumnChanged($item, $column, $old_value, $new_value, $actor);

        // A subitem only ever holds values of subitem columns (see `BoardItemValueService::sync()`,
        // which also loads the column without its scope), so its parent is all that needs checking.
        if ($item->parent_id !== null) {
            $this->handleSubitemColumnChanged($item, $column, $old_value, $new_value, $actor);
        } elseif (! $this->valuesAreEqual($old_value, $new_value)) {
            $this->handleItemCreatedOrUpdated($item, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * Fires every `item_created_or_updated` automation of the item's tab, for a root item that was
     * just created, renamed or had a column value changed.
     *
     * @param  array<string, mixed>  $context
     */
    private function handleItemCreatedOrUpdated(BoardItem $item, ?User $actor, array $context = []): void
    {
        if ($item->parent_id !== null) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_CREATED_OR_UPDATED) as $automation) {
            $this->executeAutomation($automation, $item, $actor, $context);
        }
    }

    /**
     * Fires every `subitem_column_changed` automation watching the subitem column that just changed,
     * on the parent item (or on the subitem itself with `trigger_config.run_on` `subitem`). Only once
     * the new value passes `trigger_config.match` when one is set, read by the column's type like
     * {@see self::changeMatches()}. The subitem is kept in the context as `subitem_id`, which
     * `{subitem_name}` reads.
     */
    private function handleSubitemColumnChanged(BoardItem $subitem, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($this->valuesAreEqual($old_value, $new_value)) {
            return;
        }

        $automations = $this->enabledAutomations($subitem, BoardAutomation::TRIGGER_SUBITEM_COLUMN_CHANGED, $column->id);
        if ($automations->isEmpty()) {
            return;
        }

        $parent = null;
        foreach ($automations as $automation) {
            $match = $automation->trigger_config['match'] ?? null;
            if (is_array($match) && ! empty($match['operator']) && ! $this->changeMatches($column, $old_value, $new_value, $match)) {
                continue;
            }

            $subject = $subitem;
            if (! $automation->runsOnSubitem()) {
                $parent ??= BoardItem::with('group')->find($subitem->parent_id);
                if (! $parent || $parent->is_archived) {
                    continue;
                }
                $subject = $parent;
            }

            $this->executeAutomation($automation, $subject, $actor, [...$this->changeContext($column, $old_value, $new_value), 'subitem_id' => $subitem->id]);
        }
    }

    /**
     * Fires every `number_threshold` automation whose threshold the new number reaches while the
     * old one did not, so "goes above 100" fires once when it crosses, not on every change above.
     * A time tracking column is read in hours.
     */
    private function handleNumberThreshold(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $new_number = $this->numberOf($column, $new_value);
        if ($new_number === null) {
            return;
        }
        $old_number = $this->numberOf($column, $old_value);

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_NUMBER_THRESHOLD, $column->id) as $automation) {
            $config = (array) ($automation->trigger_config ?? []);
            if (! is_numeric($config['threshold'] ?? null)) {
                continue;
            }
            $operator = (string) ($config['operator'] ?? 'above');
            $threshold = (float) $config['threshold'];

            if ($this->numberHolds($new_number, $operator, $threshold) && ($old_number === null || ! $this->numberHolds($old_number, $operator, $threshold))) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
            }
        }
    }

    /**
     * A number cell as a float, a time tracking value in hours, null when it holds no number.
     */
    public function numberOf(BoardColumn $column, mixed $value): ?float
    {
        if ($column->type === BoardColumn::TYPE_TIME_TRACKING) {
            return is_array($value) && isset($value['seconds']) ? round(((int) $value['seconds']) / 3600, 4) : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function numberHolds(float $number, string $operator, float $threshold): bool
    {
        return match ($operator) {
            'below' => $number < $threshold,
            'equals' => abs($number - $threshold) < 0.00001,
            default => $number > $threshold,
        };
    }

    /**
     * A checklist change fires `checklist_completed` once its last open task is checked and
     * `checklist_item_checked` for tasks that were just checked (only the task named
     * `trigger_value` when set, compared without case).
     */
    private function handleChecklistChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $old_tasks = $this->checklistTasks($old_value);
        $new_tasks = $this->checklistTasks($new_value);
        $is_complete = fn (array $tasks) => $tasks !== [] && collect($tasks)->every(fn (array $task) => $task['is_done']);

        if ($is_complete($new_tasks) && ! $is_complete($old_tasks)) {
            foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_CHECKLIST_COMPLETED, $column->id) as $automation) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, null, array_column($new_tasks, 'text')));
            }
        }

        $was_done = collect($old_tasks)->filter(fn (array $task) => $task['is_done'])->pluck('key')->all();
        $just_checked = array_values(array_filter($new_tasks, fn (array $task) => $task['is_done'] && ! in_array($task['key'], $was_done, true)));
        if ($just_checked === []) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_CHECKLIST_ITEM_CHECKED, $column->id) as $automation) {
            $wanted = mb_strtolower(trim((string) ($automation->trigger_value ?? '')));
            $matching = $wanted === '' ? $just_checked : array_values(array_filter($just_checked, fn (array $task) => mb_strtolower(trim($task['text'])) === $wanted));
            if ($matching !== []) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, null, array_column($matching, 'text')));
            }
        }
    }

    /**
     * A checklist value as `[{key, text, is_done}]`, keyed by task id or, without one, its text.
     *
     * @return array<int, array{key: string, text: string, is_done: bool}>
     */
    private function checklistTasks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tasks = [];
        foreach ($value as $task) {
            if (! is_array($task)) {
                continue;
            }
            $text = trim((string) ($task['text'] ?? ''));
            $tasks[] = ['key' => (string) ($task['id'] ?? $text), 'text' => $text, 'is_done' => (bool) ($task['is_done'] ?? false)];
        }

        return $tasks;
    }

    /**
     * Fires every `button_clicked` automation watching the button column that was just pressed.
     *
     * @return int How many automations ran.
     */
    public function handleButtonClicked(BoardItem $item, BoardColumn $column, ?User $actor): int
    {
        $automations = $this->enabledAutomations($item, BoardAutomation::TRIGGER_BUTTON_CLICKED, $column->id);
        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $item, $actor, ['column' => $column]);
        }

        return $automations->count();
    }

    /**
     * Fires on the parent item once a subitem's status change leaves every (not archived) subitem
     * of that parent with the automation's `trigger_value`.
     */
    private function handleAllSubitemsStatus(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($item->parent_id === null || (string) $new_value === (string) $old_value || $new_value === null || $new_value === '') {
            return;
        }

        $automations = $this->enabledAutomations($item, BoardAutomation::TRIGGER_ALL_SUBITEMS_STATUS, $column->id)
            ->filter(fn (BoardAutomation $automation) => (string) $automation->trigger_value === (string) $new_value);
        if ($automations->isEmpty()) {
            return;
        }

        $parent = BoardItem::with('group')->find($item->parent_id);
        if (! $parent || ! $this->everyItemHolds(BoardItem::where('parent_id', $parent->id), $column, (string) $new_value)) {
            return;
        }

        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $parent, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * Fires once a top-level item's status change leaves every (not archived) item of its group
     * with the automation's `trigger_value`, narrowed to one group by `trigger_config.group_id`.
     */
    private function handleAllGroupItemsStatus(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($item->parent_id !== null || (string) $new_value === (string) $old_value || $new_value === null || $new_value === '') {
            return;
        }

        $automations = $this->enabledAutomations($item, BoardAutomation::TRIGGER_ALL_GROUP_ITEMS_STATUS, $column->id)
            ->filter(fn (BoardAutomation $automation) => (string) $automation->trigger_value === (string) $new_value)
            ->filter(fn (BoardAutomation $automation) => empty($automation->trigger_config['group_id']) || (int) $automation->trigger_config['group_id'] === $item->group_id);
        if ($automations->isEmpty()) {
            return;
        }

        if (! $this->everyItemHolds(BoardItem::where('group_id', $item->group_id)->whereNull('parent_id'), $column, (string) $new_value)) {
            return;
        }

        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * Whether every not archived item of `$query` holds `$option_id` in `$column`.
     *
     * @param  Builder<BoardItem>  $query
     */
    private function everyItemHolds(Builder $query, BoardColumn $column, string $option_id): bool
    {
        $item_ids = $query->where('is_archived', false)->pluck('id');
        if ($item_ids->isEmpty()) {
            return false;
        }

        $values = BoardItemValue::whereIn('item_id', $item_ids)->where('column_id', $column->id)->pluck('value', 'item_id');

        return $item_ids->every(fn (int $id) => (string) ($values[$id] ?? '') === $option_id);
    }

    /**
     * Fires every `date_changed` automation watching a date or timeline column once its value is
     * set, moved or cleared.
     */
    private function handleDateChanged(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        if ($this->valuesAreEqual($old_value, $new_value)) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_DATE_CHANGED, $column->id) as $automation) {
            if (! $this->timelinePartChanged($column, (string) ($automation->trigger_config['timeline_part'] ?? 'any'), $old_value, $new_value)) {
                continue;
            }
            $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
        }
    }

    /**
     * Whether the watched end of a timeline moved: `start`, `end`, or `any` for either. A date
     * column only has one day, so any change counts.
     */
    private function timelinePartChanged(BoardColumn $column, string $part, mixed $old_value, mixed $new_value): bool
    {
        if ($column->type !== BoardColumn::TYPE_TIMELINE || ! in_array($part, ['start', 'end'], true)) {
            return true;
        }

        $day = fn (mixed $value): ?string => is_array($value) && is_string($value[$part] ?? null) ? substr($value[$part], 0, 10) : null;

        return $day($old_value) !== $day($new_value);
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
            $match = $automation->trigger_config['match'] ?? null;
            if (is_array($match) && ! empty($match['operator'])) {
                if (! $this->changeMatches($column, $old_value, $new_value, $match)) {
                    continue;
                }
            } elseif ($automation->trigger_value !== null && ! $this->valueMatches($column, $new_value, $automation->trigger_value)) {
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

    /**
     * Fires once for every person just removed from a `people` column, a `trigger_value` of null
     * watches for anyone being removed, a specific user id only for that person.
     */
    private function handlePersonUnassigned(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $old_ids = array_map('strval', is_array($old_value) ? $old_value : []);
        $new_ids = array_map('strval', is_array($new_value) ? $new_value : []);
        $removed_ids = array_values(array_diff($old_ids, $new_ids));

        if ($removed_ids === []) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_PERSON_UNASSIGNED, $column->id) as $automation) {
            $watched_user_id = $automation->trigger_value;
            if ($watched_user_id === null || in_array((string) $watched_user_id, $removed_ids, true)) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, $old_value, $new_value));
            }
        }
    }

    /**
     * Fires once a `files` column gains a file or link, compared by entry id. `trigger_config.extensions`
     * narrows it to files of those types (`pdf`, `png`...), a link only counts when its address ends with one.
     */
    private function handleFilesAdded(BoardItem $item, BoardColumn $column, mixed $old_value, mixed $new_value, ?User $actor): void
    {
        $key = fn (mixed $entry) => is_array($entry) ? (string) ($entry['id'] ?? ($entry['url'] ?? ($entry['file_name'] ?? ''))) : '';
        $old_keys = array_map($key, is_array($old_value) ? $old_value : []);
        $added = array_values(array_filter(is_array($new_value) ? $new_value : [], fn ($entry) => is_array($entry) && ! in_array($key($entry), $old_keys, true)));

        if ($added === []) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_FILE_UPLOADED, $column->id) as $automation) {
            $extensions = array_map(fn ($extension) => ltrim(mb_strtolower(trim((string) $extension)), '.'), (array) ($automation->trigger_config['extensions'] ?? []));
            $extensions = array_values(array_filter($extensions));
            $matching = $extensions === [] ? $added : array_values(array_filter($added, function (array $entry) use ($extensions) {
                $name = mb_strtolower((string) (($entry['file_name'] ?? '') ?: ($entry['url'] ?? '')));
                $path = parse_url($name, PHP_URL_PATH);

                return in_array(pathinfo(is_string($path) && $path !== '' ? $path : $name, PATHINFO_EXTENSION), $extensions, true);
            }));

            if ($matching !== []) {
                $this->executeAutomation($automation, $item, $actor, $this->changeContext($column, null, array_map(fn (array $entry) => (string) ($entry['file_name'] ?? ''), $matching)));
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

        $this->handleItemCreatedOrUpdated($item, $actor);
    }

    /**
     * Reacts to an update or a reply having just been posted on `$item`: `update_posted` (updates
     * only), `update_replied` (replies only), `update_keyword` (updates, and replies when the
     * automation asks for them) and `user_mentioned` (once for every person mentioned in either).
     * Called wherever an update goes live, so mentions must already be stored.
     */
    public function handleUpdatePosted(BoardItem $item, BoardItemComment $comment, ?User $actor): void
    {
        $context = ['update_text' => MarkdownPlainText::convert((string) $comment->body)];
        $is_reply = $comment->parent_id !== null;

        if (! $is_reply) {
            foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_UPDATE_POSTED) as $automation) {
                $this->executeAutomation($automation, $item, $actor, $context);
            }
        } else {
            foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_UPDATE_REPLIED) as $automation) {
                if ($automation->trigger_value !== null && (string) $automation->trigger_value !== (string) $actor?->id) {
                    continue;
                }
                $this->executeAutomation($automation, $item, $actor, $context);
            }
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_UPDATE_KEYWORD) as $automation) {
            $config = (array) ($automation->trigger_config ?? []);
            if ($is_reply && empty($config['include_replies'])) {
                continue;
            }
            if ($this->matchedKeyword($context['update_text'], (array) ($config['keywords'] ?? [])) !== null) {
                $this->executeAutomation($automation, $item, $actor, $context);
            }
        }

        $this->handleMentions($item, $comment, $actor, $context);
    }

    /**
     * Fires `user_mentioned` once for every person the update mentions, a `trigger_value` of null
     * watches for anyone being mentioned, a user id only for that person. The person is kept in the
     * context as `mentioned_user_id`, which "the mentioned person" and `{mentioned_name}` read.
     *
     * @param  array<string, mixed>  $context
     */
    private function handleMentions(BoardItem $item, BoardItemComment $comment, ?User $actor, array $context): void
    {
        $mentioned_ids = $comment->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
        if ($mentioned_ids->isEmpty()) {
            return;
        }

        $automations = $this->enabledAutomations($item, BoardAutomation::TRIGGER_USER_MENTIONED);
        foreach ($mentioned_ids as $mentioned_id) {
            foreach ($automations as $automation) {
                if ($automation->trigger_value !== null && (string) $automation->trigger_value !== (string) $mentioned_id) {
                    continue;
                }
                $this->executeAutomation($automation, $item, $actor, [...$context, 'mentioned_user_id' => $mentioned_id]);
            }
        }
    }

    /**
     * The first of `$keywords` found in `$text`, compared without case, null when none is.
     *
     * @param  array<int, mixed>  $keywords
     */
    public function matchedKeyword(string $text, array $keywords): ?string
    {
        $haystack = mb_strtolower($text);
        foreach ($keywords as $keyword) {
            $needle = mb_strtolower(trim(is_scalar($keyword) ? (string) $keyword : ''));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return (string) $keyword;
            }
        }

        return null;
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

    /**
     * Reacts to an item having just been renamed from `$old_name`.
     */
    public function handleNameChanged(BoardItem $item, string $old_name, ?User $actor): void
    {
        if (trim($old_name) === trim((string) $item->name)) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_NAME_CHANGED) as $automation) {
            $this->executeAutomation($automation, $item, $actor, ['old_text' => $old_name, 'new_text' => (string) $item->name]);
        }

        $this->handleItemCreatedOrUpdated($item, $actor, ['old_text' => $old_name, 'new_text' => (string) $item->name]);
    }

    /**
     * Reacts to a board form having just created `$item`, `trigger_config.form_view_id` narrows an
     * automation to one form.
     */
    public function handleFormSubmitted(BoardItem $item, int $form_view_id): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_FORM_SUBMITTED) as $automation) {
            $form_id = $automation->trigger_config['form_view_id'] ?? null;
            if ($form_id !== null && (int) $form_id !== $form_view_id) {
                continue;
            }

            $this->executeAutomation($automation, $item, null);
        }
    }

    /**
     * Runs a "When a webhook is received" automation with the JSON it was sent, which its actions
     * read through `{payload.*}` tokens. There is no item until an action creates one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(BoardAutomation $automation, array $payload): void
    {
        $automation->loadMissing(['board', 'creator', 'owner']);
        $this->executeAutomation($automation, null, null, ['payload' => $payload]);
    }

    /**
     * Runs an "email is received" automation for one new email of its connected inbox, the email
     * read through `{payload.*}` tokens like a webhook body.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleEmailReceived(BoardAutomation $automation, array $payload): void
    {
        $automation->loadMissing(['board', 'creator', 'owner']);
        $this->executeAutomation($automation, null, null, ['payload' => $payload]);
    }

    /**
     * Reacts to a top-level item having just arrived on this board from `$from_board_id`,
     * `trigger_config.from_board_id` narrows an automation to one source board.
     */
    public function handleItemMovedToBoard(BoardItem $item, int $from_board_id, ?User $actor): void
    {
        if ($item->parent_id !== null) {
            return;
        }

        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_MOVED_TO_BOARD) as $automation) {
            $source_board_id = $automation->trigger_config['from_board_id'] ?? null;
            if ($source_board_id !== null && (int) $source_board_id !== $from_board_id) {
                continue;
            }

            $this->executeAutomation($automation, $item, $actor);
        }
    }

    /**
     * Reacts to an archived or deleted item having just been restored.
     */
    public function handleItemRestored(BoardItem $item, ?User $actor): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_RESTORED) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }
    }

    public function handleItemArchived(BoardItem $item, ?User $actor): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_ARCHIVED) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }

        $this->forgetCalendarEvents($item);
    }

    /**
     * An archived or deleted item takes its synced Google Calendar events with it. A test run
     * archives inside a transaction that is rolled back, so nothing leaves the app then.
     */
    private function forgetCalendarEvents(BoardItem $item): void
    {
        if (! $this->run_context->isDryRun()) {
            app(CalendarEventSyncer::class)->forgetItem($item);
        }
    }

    public function handleItemDeleted(BoardItem $item, ?User $actor): void
    {
        foreach ($this->enabledAutomations($item, BoardAutomation::TRIGGER_ITEM_DELETED) as $automation) {
            $this->executeAutomation($automation, $item, $actor);
        }

        $this->forgetCalendarEvents($item);
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
     * With `trigger_config.working_days_only` the offset counts working days of the board's
     * calendar and the trigger never fires on a non working day: a date that falls on one fires on
     * the working day before it.
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
            if ($this->isBoardPaused($automation->board_id)) {
                continue;
            }

            $config = (array) ($automation->trigger_config ?? []);
            $local_now = $now->setTimezone(AutomationSchedule::timezone($config['timezone'] ?? null));
            $today = $local_now->toDateString();
            $offset_days = (int) ($config['offset_days'] ?? 0);
            $configured_time = $config['time'] ?? null;
            $calendar = ! empty($config['working_days_only']) ? BoardAutomationSetting::forBoard($automation->board_id)->calendar() : null;

            if ($configured_time !== null && ! $this->timeHasPassed($local_now, $configured_time)) {
                continue;
            }
            if ($calendar !== null && ! $calendar->isWorkingDay(CarbonImmutable::parse($today))) {
                continue;
            }

            $already_ran_item_ids = BoardAutomationRun::where('automation_id', $automation->id)
                ->whereDate('ran_on', $today)
                ->pluck('board_item_id');

            $due_query = BoardItemValue::where('column_id', $automation->trigger_column_id)->whereNotIn('item_id', $already_ran_item_ids)->with('item.group');
            if ($calendar === null) {
                $this->whereDateValue($due_query, $local_now->subDays($offset_days)->toDateString(), null);
            } else {
                // Working day offsets stretch over weekends and holidays, so look a little wider and
                // keep the dates whose working day trigger falls on today.
                $slack = abs($offset_days) * 3 + 15;
                $this->whereDateValue($due_query, $local_now->subDays($offset_days + $slack)->toDateString(), $local_now->subDays($offset_days - $slack)->toDateString());
            }
            $due_values = $due_query->get();

            foreach ($due_values as $value) {
                if ($calendar !== null) {
                    $date = is_string($value->value) ? substr($value->value, 0, 10) : '';
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                        continue;
                    }
                    $base = CarbonImmutable::parse($date);
                    $fires_on = $offset_days === 0 ? $calendar->previousWorkingDay($base) : $calendar->addWorkingDays($base, $offset_days);
                    if ($fires_on->toDateString() !== $today) {
                        continue;
                    }
                }

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
     * Runs every `item_overdue` automation whose items became overdue: the date (or the timeline's
     * end) is before today in the automation's time zone, the configured time of day has passed and
     * the item is not done (see {@see BoardAutomation::TRIGGER_ITEM_OVERDUE}). Each item fires once
     * per due date, recorded in {@see BoardAutomationRun} under that date, so moving the date and
     * missing it again fires again. Dates before the automation was created are left alone.
     *
     * @return int How many (automation, item) pairs ran.
     */
    public function runOverdueTriggers(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_ITEM_OVERDUE)
            ->whereNotNull('trigger_column_id')
            ->with(['triggerColumn', 'board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            $column = $automation->triggerColumn;
            if (! $column || $this->isBoardPaused($automation->board_id)) {
                continue;
            }

            $config = (array) ($automation->trigger_config ?? []);
            $local_now = $now->setTimezone(AutomationSchedule::timezone($config['timezone'] ?? null));
            if (! $this->timeHasPassed($local_now, (string) ($config['time'] ?? self::DEFAULT_DATE_TRIGGER_TIME))) {
                continue;
            }

            $today = $local_now->toDateString();
            $earliest = CarbonImmutable::instance($automation->created_at ?? $now)->setTimezone($local_now->getTimezone())->toDateString();
            $status_column_id = isset($config['status_column_id']) ? (int) $config['status_column_id'] : null;
            $done_values = array_map('strval', (array) ($config['done_values'] ?? []));

            $values = BoardItemValue::where('column_id', $column->id)->with('item.group', 'item.values')->get();
            foreach ($values as $value) {
                $due = $this->dueDateOf($column, $value->value);
                if ($due === null || $due >= $today || $due < $earliest) {
                    continue;
                }

                $item = $value->item;
                if (! $item || $item->is_archived || $item->group?->board_view_id !== $automation->board_view_id) {
                    continue;
                }
                if ($status_column_id !== null && $done_values !== []) {
                    $status = $item->values->firstWhere('column_id', $status_column_id)?->value;
                    if (in_array((string) $status, $done_values, true)) {
                        continue;
                    }
                }

                if (BoardAutomationRun::where('automation_id', $automation->id)->where('board_item_id', $item->id)->whereDate('ran_on', $due)->exists()) {
                    continue;
                }
                try {
                    // The unique index claims the pair, so an overlapping scheduler tick never runs it twice.
                    BoardAutomationRun::create(['automation_id' => $automation->id, 'board_item_id' => $item->id, 'ran_on' => $due]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                $this->executeAutomation($automation, $item, null, $this->changeContext($column, null, $value->value));
                $ran_count++;
            }
        }

        return $ran_count;
    }

    /**
     * Runs every "status is stuck" and "item is not updated" automation, see
     * {@see BoardAutomation::quietTriggers()}. An item fires once its label (or its last update)
     * is older than the automation's quiet period, once per stretch: the stretch is recorded in
     * {@see BoardAutomationRun} under the moment it began, so a new change starts a new stretch.
     * Each automation acts on at most {@see self::MAX_SCAN_ITEMS} items per check, the ones quiet
     * the longest first, and the rest follow on the next checks.
     *
     * @return int How many (automation, item) pairs ran.
     */
    public function runQuietTriggers(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->whereIn('trigger_type', BoardAutomation::quietTriggers())
            ->with(['triggerColumn', 'board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            $minutes = $automation->quietPeriodMinutes();
            if ($minutes === null || $this->isBoardPaused($automation->board_id)) {
                continue;
            }

            $candidates = $automation->trigger_type === BoardAutomation::TRIGGER_STATUS_STUCK
                ? $this->stuckItems($automation, $now->subMinutes($minutes))
                : $this->staleItems($automation, $now->subMinutes($minutes));

            foreach ($candidates as [$item, $since, $context]) {
                if (! $this->claimStretch($automation, $item, $since)) {
                    continue;
                }

                $this->executeAutomation($automation, $item, null, $context);
                $ran_count++;
            }
        }

        return $ran_count;
    }

    /**
     * Items whose status column has held the automation's label (any label when none is set)
     * since `$cutoff` or before, oldest first.
     *
     * @return array<int, array{0: BoardItem, 1: CarbonImmutable, 2: array<string, mixed>}>
     */
    public function stuckItems(BoardAutomation $automation, CarbonImmutable $cutoff): array
    {
        $column = $automation->triggerColumn;
        if (! $column || $column->board_view_id !== $automation->board_view_id) {
            return [];
        }

        $label = $automation->trigger_value;
        $values = BoardItemValue::where('column_id', $column->id)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->with('item.group')
            ->get();

        $stuck = [];
        foreach ($values as $value) {
            if ($value->value === null || $value->value === '' || ($label !== null && $label !== '' && (string) $value->value !== (string) $label)) {
                continue;
            }
            $item = $value->item;
            if (! $item || $item->is_archived || $item->group?->board_view_id !== $automation->board_view_id || $item->group->is_archived) {
                continue;
            }

            $stuck[] = [$item, CarbonImmutable::instance($value->updated_at), $this->changeContext($column, null, $value->value)];
            if (count($stuck) >= self::MAX_SCAN_ITEMS) {
                break;
            }
        }

        return $stuck;
    }

    /**
     * Top level items with no change to their name, values or updates since `$cutoff`, only of
     * `trigger_config.group_id` when set, quiet the longest first.
     *
     * @return array<int, array{0: BoardItem, 1: CarbonImmutable, 2: array<string, mixed>}>
     */
    public function staleItems(BoardAutomation $automation, CarbonImmutable $cutoff): array
    {
        $group_id = $automation->trigger_config['group_id'] ?? null;
        $stale = [];

        // An item's own timestamp only moves forward with its values, so it narrows the scan cheaply.
        BoardItem::query()
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('group', fn ($query) => $query->where('board_view_id', $automation->board_view_id)->where('is_archived', false))
            ->when($group_id, fn ($query) => $query->where('group_id', (int) $group_id))
            ->with(['values', 'group'])
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $items) use ($cutoff, &$stale) {
                $last_updates = BoardItemComment::whereIn('item_id', $items->modelKeys())
                    ->groupBy('item_id')
                    ->selectRaw('item_id, MAX(created_at) as last_at')
                    ->toBase()
                    ->pluck('last_at', 'item_id');

                foreach ($items as $item) {
                    $last_at = BoardItemFilterEvaluator::lastUpdatedAt($item);
                    $last_update = isset($last_updates[$item->id]) ? CarbonImmutable::parse($last_updates[$item->id]) : null;
                    if ($last_update !== null && ($last_at === null || $last_update->greaterThan($last_at))) {
                        $last_at = $last_update;
                    }
                    if ($last_at === null || $last_at->greaterThan($cutoff)) {
                        continue;
                    }
                    $stale[] = [$item, CarbonImmutable::instance($last_at), []];
                }
            });

        usort($stale, fn (array $a, array $b) => $a[1] <=> $b[1]);

        return array_slice($stale, 0, self::MAX_SCAN_ITEMS);
    }

    /**
     * Records that the automation fired for the stretch that began at `$since`, false when it
     * already had, the unique index settles two overlapping scheduler checks.
     */
    private function claimStretch(BoardAutomation $automation, BoardItem $item, CarbonImmutable $since): bool
    {
        $attributes = [
            'automation_id' => $automation->id,
            'board_item_id' => $item->id,
            'ran_on' => $since->toDateString(),
            'anchor' => $since->format('Y-m-d H:i:s'),
        ];

        if (BoardAutomationRun::where('automation_id', $automation->id)->where('board_item_id', $item->id)->where('anchor', $attributes['anchor'])->exists()) {
            return false;
        }

        try {
            BoardAutomationRun::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * The day a date or timeline value is due, `YYYY-MM-DD`, the timeline's end.
     */
    private function dueDateOf(BoardColumn $column, mixed $value): ?string
    {
        $raw = $column->type === BoardColumn::TYPE_TIMELINE ? (is_array($value) ? ($value['end'] ?? $value['start'] ?? null) : null) : $value;
        $day = is_string($raw) ? substr($raw, 0, 10) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }

    /**
     * Narrows a date cell query to one day (`$to` null) or to a range of days, whether the value
     * is a plain date or carries a time.
     *
     * @param  Builder<BoardItemValue>  $query
     */
    private function whereDateValue(Builder $query, string $from, ?string $to): void
    {
        $is_mysql = DB::connection()->getDriverName() === 'mysql';

        if ($to === null) {
            $is_mysql ? $query->whereRaw('JSON_UNQUOTE(`value`) LIKE ?', [$from.'%']) : $query->where('value', 'like', '"'.$from.'%');

            return;
        }

        if ($is_mysql) {
            $query->whereRaw('JSON_UNQUOTE(`value`) >= ?', [$from])->whereRaw('JSON_UNQUOTE(`value`) <= ?', [$to.'~']);
        } else {
            $query->where('value', '>=', '"'.$from)->where('value', '<=', '"'.$to.'~');
        }
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
            ->whereIn('trigger_type', BoardAutomation::scheduledTriggers())
            ->with(['board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            if ($this->isBoardPaused($automation->board_id)) {
                continue;
            }

            $occurrence = AutomationSchedule::latestOccurrence((array) ($automation->trigger_config['schedule'] ?? []), $now);
            if ($occurrence === null) {
                continue;
            }

            $last_run_at = $automation->last_scheduled_run_at ?? $automation->created_at;
            if ($last_run_at !== null && $occurrence->lessThanOrEqualTo($last_run_at)) {
                continue;
            }

            $automation->forceFill(['last_scheduled_run_at' => $now])->saveQuietly();

            if ($automation->trigger_type === BoardAutomation::TRIGGER_ITEM_SCAN) {
                $this->runItemScan($automation);
            } else {
                $this->executeAutomation($automation, null, null);
            }
            $ran_count++;
        }

        return $ran_count;
    }

    /**
     * One run of a scheduled item scan: the actions run once on every top-level item of the tab
     * that passes the conditions, up to {@see self::MAX_SCAN_ITEMS}. Items that do not pass leave
     * no trace in the run history, a daily scan would otherwise bury it.
     *
     * @return int How many items the actions ran on.
     */
    public function runItemScan(BoardAutomation $automation): int
    {
        if ($this->pauseIfBroken($automation)) {
            return 0;
        }

        $matched = 0;
        BoardItem::query()
            ->whereNull('parent_id')
            ->where('is_archived', false)
            ->whereHas('group', fn ($query) => $query->where('board_view_id', $automation->board_view_id)->where('is_archived', false))
            ->with(['values', 'group'])
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $items) use ($automation, &$matched) {
                foreach ($items as $item) {
                    if ($matched >= self::MAX_SCAN_ITEMS) {
                        return false;
                    }
                    if (! $this->conditionsMatch($automation, $item)) {
                        continue;
                    }

                    $this->executeAutomation($automation, $item, null, [], is_prechecked: true);
                    $matched++;
                }

                return true;
            });

        return $matched;
    }

    // ── Running ───────────────────────────────────────────────────────────────

    /**
     * Checks the conditions, then runs the "Then" actions in order, or the "Otherwise" actions when
     * the item does not pass them. Writes one run history row per action, all sharing a run id. An
     * action that throws is recorded as failed and never breaks the change that triggered the
     * automation. The owner is told about failures.
     *
     * @param  array<string, mixed>  $context  what the trigger knows (`column`, `old_value`, `new_value`, `update_text`)
     * @param  bool  $is_prechecked  the caller already checked the conditions, as a scheduled item scan does
     */
    private function executeAutomation(BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = [], bool $is_prechecked = false): void
    {
        if (! $this->run_context->isDryRun() && $this->isBoardPaused($automation->board_id)) {
            return;
        }

        $this->guarded($automation, $item, $actor, function () use ($automation, $item, $actor, $context, $is_prechecked) {
            $run_uuid = (string) Str::uuid();
            $branch = 'then';

            if ($item !== null && ! $is_prechecked && ! $this->conditionsMatch($automation, $item, $actor)) {
                if ($automation->resolvedElseActions() === []) {
                    $this->recordRun($automation, $item, $actor, $this->firstActionType($automation), BoardAutomationActionOutcome::skipped('The conditions were not met.'), $run_uuid);

                    return;
                }
                $branch = 'else';
            }

            $this->runBranch($automation, $branch, 0, $item, $actor, $context, $run_uuid);
            $this->settleRunHealth($automation, $run_uuid);
        });
    }

    /**
     * Runs `$callback` inside the loop guards every run shares: the chain depth limit, the same
     * automation never twice on the same item in one chain, and a broken automation pausing itself.
     */
    private function guarded(BoardAutomation $automation, ?BoardItem $item, ?User $actor, callable $callback): void
    {
        $key = $automation->id.':'.($item?->id ?? 'none');

        if ($this->depth >= self::MAX_CHAIN_DEPTH) {
            $this->recordRun($automation, $item, $actor, $this->firstActionType($automation), BoardAutomationActionOutcome::skipped(
                'Stopped because too many automations triggered each other in a row.'
            ));

            return;
        }

        if (isset($this->running[$key])) {
            $this->recordRun($automation, $item, $actor, $this->firstActionType($automation), BoardAutomationActionOutcome::skipped(
                'Skipped to prevent a loop, this automation already ran on this item in the same chain.'
            ));

            return;
        }

        if ($this->pauseIfBroken($automation)) {
            return;
        }

        $this->running[$key] = true;
        $this->depth++;
        $this->run_context->enter($automation);

        try {
            $callback();
        } finally {
            $this->run_context->leave();
            $this->depth--;
            unset($this->running[$key]);
        }
    }

    /**
     * Runs the actions of one branch from `$start_index`. A "wait" action stores the rest of the
     * branch for later and stops here, except in a test run, which only says it would wait.
     *
     * @param  array<string, mixed>  $context
     */
    private function runBranch(BoardAutomation $automation, string $branch, int $start_index, ?BoardItem $item, ?User $actor, array $context, string $run_uuid): void
    {
        $actions = $automation->branchActions($branch);
        $subject = $item;
        $this->run_context->bindRun($run_uuid);

        for ($index = $start_index; $index < count($actions); $index++) {
            $action = $actions[$index];

            if ($action['type'] === BoardAutomation::ACTION_WAIT) {
                $outcome = $this->scheduleWait($automation, $action['params'], $branch, $index + 1, $subject, $actor, $context, $run_uuid, $index + 1 < count($actions));
                $this->recordRun($automation, $subject, $actor, $action['type'], $outcome, $run_uuid, $branch, $index);
                if ($this->run_context->isDryRun()) {
                    continue;
                }

                return;
            }

            // The monthly action quota, a test run never counts nor stops.
            $is_counted = ! $this->run_context->isDryRun();
            if ($is_counted && $this->usage_meter->isExhausted()) {
                $this->recordRun($automation, $subject, $actor, $action['type'], BoardAutomationActionOutcome::skipped($this->usage_meter->exhaustedMessage()), $run_uuid, $branch, $index);

                return;
            }

            try {
                $outcome = $this->action_runner->run($automation, $action, $subject, $actor, $context, "{$branch}.{$index}");
            } catch (Throwable $exception) {
                report($exception);
                $outcome = BoardAutomationActionOutcome::failed('The action failed unexpectedly.');
            }

            if ($is_counted && $outcome->status !== BoardAutomationRunLog::STATUS_SKIPPED) {
                $this->usage_meter->recordAction();
            }

            $is_failed = $outcome->status === BoardAutomationRunLog::STATUS_FAILED;
            $this->recordRun($automation, $subject ?? $outcome->created_item, $actor, $action['type'], $outcome, $run_uuid, $branch, $index, $is_failed ? $context : null);

            if ($is_failed) {
                $this->notifyOwnerOfFailure($automation, $subject, $outcome->message);
            }
            if ($subject === null && $outcome->created_item !== null) {
                $subject = $outcome->created_item;
            }
            if ($outcome->stops_chain) {
                break;
            }
        }
    }

    /**
     * Stores the rest of a branch behind a "wait" step, see {@see BoardAutomationDelayedRun}.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function scheduleWait(BoardAutomation $automation, array $params, string $branch, int $next_index, ?BoardItem $item, ?User $actor, array $context, string $run_uuid, bool $has_more): BoardAutomationActionOutcome
    {
        $amount = max(1, (int) ($params['amount'] ?? 1));
        $unit = in_array($params['unit'] ?? 'hours', ['minutes', 'hours', 'days'], true) ? (string) $params['unit'] : 'hours';
        $run_at = match ($unit) {
            'minutes' => now()->addMinutes($amount),
            'days' => now()->addDays(min($amount, BoardAutomation::MAX_WAIT_DAYS)),
            default => now()->addHours($amount),
        };
        $label = $amount.' '.($amount === 1 ? rtrim($unit, 's') : $unit);

        if (! $has_more) {
            return BoardAutomationActionOutcome::skipped("Nothing comes after the wait of {$label}.");
        }
        if ($this->run_context->isDryRun()) {
            return BoardAutomationActionOutcome::success("Would wait {$label}, then continue with the next actions.");
        }

        BoardAutomationDelayedRun::create([
            'automation_id' => $automation->id,
            'board_id' => $automation->board_id,
            'board_view_id' => $automation->board_view_id,
            'board_item_id' => $item?->id,
            'actor_id' => $actor?->id,
            'run_uuid' => $run_uuid,
            'branch' => $branch,
            'next_action_index' => $next_index,
            'recheck_conditions' => (bool) ($params['recheck_conditions'] ?? false),
            'context' => $this->serializeContext($context),
            'run_at' => $run_at,
            'status' => BoardAutomationDelayedRun::STATUS_PENDING,
        ]);

        return BoardAutomationActionOutcome::success("Waiting {$label}, the next actions run at {$run_at->toDateTimeString()} (UTC).");
    }

    /**
     * Continues every waiting run that came due, see {@see BoardAutomationDelayedRun}. A run whose
     * automation was switched off, or whose item was archived or deleted meanwhile, is cancelled.
     * Runs of a paused board keep waiting.
     *
     * @return int How many waiting runs continued.
     */
    public function runDelayedRuns(?CarbonInterface $now = null): int
    {
        $now = Carbon::instance($now ?? Carbon::now());
        $continued = 0;

        $due = BoardAutomationDelayedRun::where('status', BoardAutomationDelayedRun::STATUS_PENDING)
            ->where('run_at', '<=', $now)
            ->orderBy('run_at')
            ->limit(500)
            ->get();

        foreach ($due as $delayed) {
            if ($this->isBoardPaused($delayed->board_id)) {
                continue;
            }

            // Claimed before anything runs, so an overlapping scheduler tick never continues it twice.
            $claimed = BoardAutomationDelayedRun::whereKey($delayed->id)->where('status', BoardAutomationDelayedRun::STATUS_PENDING)->update(['status' => BoardAutomationDelayedRun::STATUS_DONE]);
            if ($claimed === 0) {
                continue;
            }

            $this->continueDelayedRun($delayed);
            $continued++;
        }

        return $continued;
    }

    private function continueDelayedRun(BoardAutomationDelayedRun $delayed): void
    {
        $automation = BoardAutomation::with(['board', 'creator', 'owner'])->find($delayed->automation_id);
        $actor = $delayed->actor_id ? User::find($delayed->actor_id) : null;
        $item = $delayed->board_item_id ? BoardItem::with(['group', 'values'])->find($delayed->board_item_id) : null;
        $cancel = function (string $message) use ($delayed, $automation, $item, $actor) {
            $delayed->forceFill(['status' => BoardAutomationDelayedRun::STATUS_CANCELLED])->save();
            if ($automation) {
                $this->recordRun($automation, $item, $actor, BoardAutomation::ACTION_WAIT, BoardAutomationActionOutcome::skipped($message), $delayed->run_uuid, $delayed->branch, max(0, $delayed->next_action_index - 1));
            }
        };

        if (! $automation || ! $automation->is_enabled) {
            $cancel('The automation was turned off while it was waiting.');

            return;
        }
        if (! $automation->board || $automation->board->is_archived) {
            $cancel('The board was archived or deleted while the automation was waiting.');

            return;
        }
        if ($delayed->board_item_id !== null && (! $item || $item->is_archived)) {
            $cancel('The item was archived or deleted while the automation was waiting.');

            return;
        }
        if ($delayed->recheck_conditions && $item !== null && ! $this->conditionsMatch($automation, $item, $actor)) {
            $cancel('The item no longer met the conditions after the wait.');

            return;
        }

        $this->guarded($automation, $item, $actor, function () use ($automation, $delayed, $item, $actor) {
            $run_uuid = $delayed->run_uuid ?? (string) Str::uuid();
            $this->runBranch($automation, $delayed->branch, $delayed->next_action_index, $item, $actor, $this->unserializeContext((array) ($delayed->context ?? [])), $run_uuid);
            $this->settleRunHealth($automation, $run_uuid);
        });
    }

    /**
     * Runs a failed step again, and the steps after it, on the same item with what the trigger knew
     * back then. The new run history rows point back at the failed one.
     */
    public function retryRun(BoardAutomationRunLog $log, ?User $actor): void
    {
        $automation = BoardAutomation::with(['board', 'creator', 'owner'])->findOrFail($log->automation_id);
        $item = $log->board_item_id ? BoardItem::with(['group', 'values'])->find($log->board_item_id) : null;

        $this->retry_of_id = $log->id;
        try {
            $this->guarded($automation, $item, $actor, function () use ($automation, $log, $item, $actor) {
                $run_uuid = (string) Str::uuid();
                $this->runBranch($automation, $log->branch ?: 'then', (int) ($log->step_index ?? 0), $item, $actor, $this->unserializeContext((array) ($log->context ?? [])), $run_uuid);
                $this->settleRunHealth($automation, $run_uuid);
            });
        } finally {
            $this->retry_of_id = null;
        }
    }

    /**
     * What the trigger knew, in a shape that can be stored as JSON: the column by id.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function serializeContext(array $context): array
    {
        $column = $context['column'] ?? null;
        unset($context['column']);
        if ($column instanceof BoardColumn) {
            $context['column_id'] = $column->id;
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function unserializeContext(array $context): array
    {
        if (isset($context['column_id'])) {
            $context['column'] = BoardColumn::find((int) $context['column_id']);
            unset($context['column_id']);
        }

        return $context;
    }

    private function firstActionType(BoardAutomation $automation): string
    {
        return $automation->resolvedActions()[0]['type'] ?? $automation->action_type;
    }

    /**
     * Whether the board's "Pause all automations" is on. Read fresh every time, the service lives
     * as long as a queue worker or a scheduler run and the switch may flip meanwhile.
     */
    public function isBoardPaused(int $board_id): bool
    {
        return BoardAutomationSetting::where('board_id', $board_id)->whereNotNull('paused_at')->exists();
    }

    /**
     * Switches `$automation` off when something it uses no longer exists, records why in the run
     * history and tells its owner. A test run only reports the problem.
     */
    private function pauseIfBroken(BoardAutomation $automation): bool
    {
        $problems = $this->health_checker->problems($automation);
        if ($problems === []) {
            return false;
        }

        $reason = $problems[0]['message'];
        $first_action_type = $automation->resolvedActions()[0]['type'] ?? $automation->action_type;

        if ($this->run_context->isDryRun()) {
            $this->recordRun($automation, null, null, $first_action_type, BoardAutomationActionOutcome::skipped("This automation would be paused: {$reason}"));

            return true;
        }

        $this->pause($automation, $reason);
        $this->recordRun($automation, null, null, $first_action_type, BoardAutomationActionOutcome::skipped("Paused: {$reason}"));

        return true;
    }

    /**
     * Pauses an automation that cannot run any more and tells its owner once.
     */
    public function pause(BoardAutomation $automation, string $reason): void
    {
        if (! $automation->is_enabled && $automation->paused_at !== null) {
            return;
        }

        $automation->forceFill(['is_enabled' => false, 'paused_at' => now(), 'paused_reason' => Str::limit($reason, 250, '')])->saveQuietly();

        $owner = $automation->responsibleUser();
        if (! $owner || ! $owner->is_active) {
            return;
        }

        try {
            $this->notification_service->notify(
                recipient: $owner,
                actor: null,
                type: Notification::TYPE_AUTOMATION,
                board: $automation->board,
                action_label: 'Automation "'.($automation->name ?: 'Automation').'" was paused',
                action_target: $reason,
                link: "/boards/{$automation->board_id}",
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Pauses every enabled automation of a tab that just lost something it uses, called once a
     * column or group is deleted.
     */
    public function pauseBrokenAutomations(int $board_view_id): int
    {
        $paused = 0;
        $automations = BoardAutomation::where('board_view_id', $board_view_id)->where('is_enabled', true)->with(['board', 'creator', 'owner'])->get();

        foreach ($automations as $automation) {
            $problems = $this->health_checker->problems($automation, fresh: true);
            if ($problems !== []) {
                $this->pause($automation, $problems[0]['message']);
                $paused++;
            }
        }

        return $paused;
    }

    // ── Test runs ─────────────────────────────────────────────────────────────

    /**
     * Runs `$automation` (saved or not) once on `$item`, inside a transaction that is always rolled
     * back, with nothing leaving the app. Returns each condition with whether the item passes it,
     * and what every action (of this automation and of any it sets off) did or would have done.
     *
     * An item that does not pass runs the "Otherwise" actions, when there are any.
     *
     * @param  array<string, mixed>  $payload  a sample webhook body for a webhook trigger
     * @return array{conditions: array<int, array{index: int, passes: bool}>, groups: array<int, array{index: int, passes: bool}>, passes: bool, branch: string|null, actions: array<int, array<string, mixed>>}
     */
    public function testRun(BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $payload = []): array
    {
        $conditions = [];
        $groups = [];
        if ($item !== null) {
            foreach (array_values((array) ($automation->conditions ?? [])) as $index => $rule) {
                $conditions[] = ['index' => $index, 'passes' => is_array($rule) && $this->rulesMatch($automation, $item, $actor, ['advanced_filter_rows' => [$rule]])];
            }
            foreach (array_values((array) ($automation->condition_groups ?? [])) as $index => $group) {
                $groups[] = ['index' => $index, 'passes' => is_array($group) && $this->rulesMatch($automation, $item, $actor, ['advanced_filter_groups' => [$group]])];
            }
        }
        $passes = $item === null || $this->conditionsMatch($automation, $item, $actor);
        $branch = $passes ? 'then' : ($automation->resolvedElseActions() !== [] ? 'else' : null);

        $this->test_outcomes = [];
        $this->test_root = $automation;
        $this->run_context->setDryRun(true);
        DB::beginTransaction();

        try {
            if ($branch !== null) {
                $context = in_array($automation->trigger_type, [BoardAutomation::TRIGGER_WEBHOOK_RECEIVED, BoardAutomation::TRIGGER_EMAIL_RECEIVED], true) ? ['payload' => $payload] : $this->testContext($automation, $item);
                $this->executeAutomation($automation, $item, $actor, $context);
            }
        } finally {
            DB::rollBack();
            $this->run_context->setDryRun(false);
            $this->test_root = null;
        }

        return ['conditions' => $conditions, 'groups' => $groups, 'passes' => $passes, 'branch' => $branch, 'actions' => $this->test_outcomes];
    }

    /**
     * What the trigger would know on a real run, read from the item as it is now.
     *
     * @return array<string, mixed>
     */
    private function testContext(BoardAutomation $automation, ?BoardItem $item): array
    {
        $column = $automation->trigger_column_id ? BoardColumn::find($automation->trigger_column_id) : null;
        if (! $column || ! $item) {
            return [];
        }

        $value = BoardItemValue::where('item_id', $item->id)->where('column_id', $column->id)->first()?->value;

        return $this->changeContext($column, null, $value);
    }

    /**
     * Whether `$item` passes the "and only if" rules and groups, combined with And or Or,
     * evaluated by the same engine the board's Advanced filters use, plus the fields only
     * automations have ({@see AutomationConditionEvaluator}). `$actor` is whoever set the
     * automation off, for "the person who made the change is". A rule on a column that no longer
     * exists is ignored.
     */
    public function conditionsMatch(BoardAutomation $automation, BoardItem $item, ?User $actor = null): bool
    {
        if (! $automation->hasConditions()) {
            return true;
        }

        return $this->rulesMatch($automation, $item, $actor, $automation->conditionFilterState());
    }

    /**
     * @param  array<string, mixed>  $filter_state  `advanced_filter_rows`, `advanced_filter_groups` and `advanced_filter_operator`
     */
    private function rulesMatch(BoardAutomation $automation, BoardItem $item, ?User $actor, array $filter_state): bool
    {
        return $this->condition_evaluator->matches($automation, $item, $actor, $filter_state);
    }

    /**
     * @param  array<string, mixed>|null  $context  kept on failed steps, so they can be retried
     */
    private function recordRun(BoardAutomation $automation, ?BoardItem $item, ?User $actor, string $action_type, BoardAutomationActionOutcome $outcome, ?string $run_uuid = null, string $branch = 'then', ?int $step_index = null, ?array $context = null): void
    {
        if ($this->run_context->isDryRun()) {
            $this->test_outcomes[] = [
                'automation_name' => $automation->name ?: 'This automation',
                'is_chained' => $automation !== $this->test_root,
                'branch' => $branch,
                'action_type' => $action_type,
                'status' => $outcome->status,
                'message' => $outcome->message,
            ];

            return;
        }

        if ($run_uuid !== null && $outcome->status !== BoardAutomationRunLog::STATUS_SKIPPED && ($this->run_outcomes[$run_uuid] ?? null) !== BoardAutomationRunLog::STATUS_FAILED) {
            $this->run_outcomes[$run_uuid] = $outcome->status;
        }

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
                'run_uuid' => $run_uuid,
                'branch' => $branch,
                'step_index' => $step_index,
                'context' => $context !== null ? $this->storableContext($context) : null,
                'retry_of_id' => $this->retry_of_id,
            ]);
        } catch (Throwable $exception) {
            // Keeping the history is best effort, it must never break the change that triggered the automation.
            report($exception);
        }
    }

    /**
     * The trigger context of a failed step as JSON, with long webhook bodies left out.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function storableContext(array $context): array
    {
        $serialized = $this->serializeContext($context);
        if (strlen((string) json_encode($serialized)) > 60000) {
            unset($serialized['payload']);
        }

        return $serialized;
    }

    /**
     * Keeps count of how many runs in a row failed: a run with a failed step adds one, a run that
     * did something without failing starts the count again, a run that only skipped leaves it.
     * Once the count reaches the board's `auto_pause_after_failures` the automation is paused and
     * its owner told, see {@see BoardAutomationSetting::autoPauseThreshold()}.
     */
    private function settleRunHealth(BoardAutomation $automation, string $run_uuid): void
    {
        $outcome = $this->run_outcomes[$run_uuid] ?? null;
        unset($this->run_outcomes[$run_uuid]);
        if ($outcome === null || $this->run_context->isDryRun() || ! $automation->exists) {
            return;
        }

        if ($outcome === BoardAutomationRunLog::STATUS_SUCCESS) {
            if ($automation->consecutive_failures > 0) {
                $automation->forceFill(['consecutive_failures' => 0])->saveQuietly();
            }

            return;
        }

        $failures = (int) $automation->consecutive_failures + 1;
        $automation->forceFill(['consecutive_failures' => $failures, 'last_failed_at' => now()])->saveQuietly();

        $threshold = BoardAutomationSetting::forBoard($automation->board_id)->autoPauseThreshold();
        if ($threshold > 0 && $failures >= $threshold && $automation->is_enabled) {
            $this->pause($automation, "It failed {$failures} runs in a row. Check the run history, fix the problem, then turn it back on.");
        }
    }

    /**
     * Tells the automation's owner that a run failed, at most once an hour per automation so a
     * broken automation on a busy board does not flood their notifications. `failure_alert` picks
     * an in-app notification, that plus an email, or nothing.
     */
    private function notifyOwnerOfFailure(BoardAutomation $automation, ?BoardItem $item, string $message): void
    {
        if ($this->run_context->isDryRun()) {
            return;
        }

        $owner = $automation->responsibleUser();
        if (! $owner || ! $owner->is_active || $automation->failure_alert === BoardAutomation::FAILURE_ALERT_NONE) {
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

            if ($automation->failure_alert === BoardAutomation::FAILURE_ALERT_APP_AND_EMAIL && $owner->email) {
                $link = $item && ! $item->trashed() ? "/boards/{$item->board_id}/pulses/{$item->id}" : "/boards/{$automation->board_id}";
                SendEmailJob::dispatch(
                    new AutomationEmail(
                        'Automation "'.($automation->name ?: 'Automation').'" failed',
                        $message.' Open the Run history of the automation to retry it.',
                        (string) ($automation->board?->label ?? 'your board'),
                        $link,
                    ),
                    $owner->email,
                );
            }
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
     * Whether a change passes a `column_changed` trigger's `trigger_config.match`, read the way the
     * column's type stores its value:
     *
     * - Text like columns: `is`, `contains`, `not_contains`, `starts_with`, `ends_with`, and
     *   `is_empty`/`is_not_empty` for a value that was just cleared or just filled in.
     * - Numbers, ratings and progress: `equals`, `greater_than`, `less_than`, `between` (`values`).
     * - Dropdown, tags, people and votes: `added` (one of `values`, any when empty, was just added),
     *   `removed` (was just removed) or `holds` (the new value holds one of `values`).
     * - Checkbox: `is_checked`, `is_unchecked`. Date: `is`, `before`, `after`, `is_empty`.
     * - Status and label: `is` or `is_not` one of `values`.
     *
     * @param  array<string, mixed>  $match  `{operator, value, values}`
     */
    public function changeMatches(BoardColumn $column, mixed $old_value, mixed $new_value, array $match): bool
    {
        $operator = (string) ($match['operator'] ?? '');
        $value = (string) ($match['value'] ?? '');
        $values = array_map('strval', array_values(array_filter((array) ($match['values'] ?? []), 'is_scalar')));
        $is_blank = fn (mixed $entry) => $entry === null || $entry === '' || $entry === [];

        if ($operator === 'is_empty') {
            return $is_blank($new_value);
        }
        if ($operator === 'is_not_empty') {
            return ! $is_blank($new_value);
        }

        switch ($column->type) {
            case BoardColumn::TYPE_CHECKBOX:
                $is_checked = in_array($new_value, [true, 1, '1', 'true'], true);

                return $operator === 'is_unchecked' ? ! $is_checked : $is_checked;
            case BoardColumn::TYPE_NUMBER:
            case BoardColumn::TYPE_RATING:
            case BoardColumn::TYPE_PROGRESS:
                $number = is_numeric($new_value) ? (float) $new_value : null;

                return (bool) BoardItemFilterEvaluator::evaluateNumberRule($number, $operator, $value, $values);
            case BoardColumn::TYPE_DROPDOWN:
            case BoardColumn::TYPE_TAGS:
            case BoardColumn::TYPE_PEOPLE:
            case BoardColumn::TYPE_VOTE:
                $old_ids = array_map('strval', array_filter((array) ($old_value ?? []), 'is_scalar'));
                $new_ids = array_map('strval', array_filter((array) ($new_value ?? []), 'is_scalar'));
                $changed = match ($operator) {
                    'added' => array_values(array_diff($new_ids, $old_ids)),
                    'removed' => array_values(array_diff($old_ids, $new_ids)),
                    default => $new_ids,
                };

                return $changed !== [] && ($values === [] || array_intersect($changed, $values) !== []);
            case BoardColumn::TYPE_STATUS:
            case BoardColumn::TYPE_LABEL:
                $holds = in_array((string) $new_value, $values, true);

                return $operator === 'is_not' ? ! $holds : $holds;
            case BoardColumn::TYPE_DATE:
                $day = is_string($new_value) ? substr($new_value, 0, 10) : '';
                if ($day === '' || $value === '') {
                    return false;
                }

                return match ($operator) {
                    'before' => $day < $value,
                    'after' => $day > $value,
                    default => $day === substr($value, 0, 10),
                };
            default:
                return (bool) BoardItemFilterEvaluator::evaluateTextOperator($this->renderer->displayValue($column, $new_value), $operator, $value);
        }
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
