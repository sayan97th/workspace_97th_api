<?php

namespace App\Http\Requests\Board\Concerns;

use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Rules\SlackActionHasConnectedWorkspace;
use App\Services\Board\AutomationConditionEvaluator;
use App\Support\AutomationSchedule;
use App\Support\BoardEditGate;
use App\Support\OutboundWebhookUrl;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by creating and editing an automation with the sentence builder: the
 * trigger (with its column, value and `trigger_config`), the "and only if" `conditions` (and
 * `condition_groups`, combined with `condition_operator`), the ordered `actions` and the
 * "Otherwise" `else_actions`, each checked against the params its type needs.
 *
 * Older clients send a single `action_type` + `action_params`, which is read as a list of one.
 */
trait ValidatesAutomationDefinition
{
    /** Operators a condition may use, the ones the board's Advanced filters offer and the ones only automations have. */
    private const CONDITION_OPERATORS = [
        'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'not_equals',
        'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'between', 'before', 'after',
        'on_or_before', 'on_or_after', 'is_checked', 'is_unchecked', 'is_empty', 'is_not_empty',
        ...AutomationConditionEvaluator::EXTRA_OPERATORS,
    ];

    /** Operators a "column changes" trigger's `trigger_config.match` may use, see `BoardAutomationService::changeMatches()`. */
    private const CHANGE_MATCH_OPERATORS = [
        'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'greater_than', 'less_than',
        'between', 'before', 'after', 'added', 'removed', 'holds', 'is_checked', 'is_unchecked', 'is_empty', 'is_not_empty',
    ];

    private const MAX_FILE_EXTENSIONS = 20;

    private const MAX_CHANGED_VALUES = 50;

    private const MAX_SUBITEM_NAMES = 20;

    private const MAX_FIELD_MAPPINGS = 30;

    /** Condition values are matched per column, a scheduled item scan without any would act on every item. */
    private const MIN_SCAN_CONDITIONS = 1;

    private const MAX_CHECKLIST_TASKS = 20;

    private const MAX_ROTATION_PEOPLE = 50;

    private const MAX_EMAIL_ADDRESSES = 10;

    /** The longest a "wait" step may be, in minutes, {@see BoardAutomation::MAX_WAIT_DAYS}. */
    private const MAX_WAIT_MINUTES = BoardAutomation::MAX_WAIT_DAYS * 24 * 60;

    /** Set when the payload came as a single `action_type` + `action_params`, whose errors keep that key. */
    private bool $uses_legacy_action = false;

    protected function prepareForValidation(): void
    {
        if (! $this->has('actions') && $this->filled('action_type')) {
            $this->uses_legacy_action = true;
            $this->merge(['actions' => [['type' => $this->input('action_type'), 'params' => (array) $this->input('action_params', [])]]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function definitionRules(bool $is_update): array
    {
        $presence = $is_update ? 'sometimes' : 'required';

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'importance' => ['sometimes', 'string', Rule::in(BoardAutomation::importanceLevels())],
            'failure_alert' => ['sometimes', 'string', Rule::in(BoardAutomation::failureAlerts())],
            'trigger_type' => [$presence, 'string', Rule::in(BoardAutomation::triggerTypes())],
            'trigger_column_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_value' => ['sometimes', 'nullable'],
            'trigger_config' => ['sometimes', 'nullable', 'array'],
            'trigger_config.from_value' => ['sometimes', 'nullable', 'string', 'max:120'],
            'trigger_config.offset_days' => ['sometimes', 'nullable', 'integer', 'between:-365,365'],
            'trigger_config.time' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'trigger_config.timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'trigger_config.group_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_config.form_view_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_config.from_board_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_config.operator' => ['sometimes', 'nullable', Rule::in(['above', 'below', 'equals'])],
            'trigger_config.threshold' => ['sometimes', 'nullable', 'numeric', 'between:-1000000000,1000000000'],
            'trigger_config.working_days_only' => ['sometimes', 'boolean'],
            'trigger_config.schedule' => ['sometimes', 'nullable', 'array'],
            'trigger_config.schedule.frequency' => ['sometimes', 'string', Rule::in(AutomationSchedule::frequencies())],
            'trigger_config.schedule.weekdays' => ['sometimes', 'array', 'max:7'],
            'trigger_config.schedule.weekdays.*' => ['integer', 'between:1,7'],
            'trigger_config.schedule.day_of_month' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'trigger_config.schedule.time' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'trigger_config.schedule.timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'trigger_config.extensions' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_FILE_EXTENSIONS],
            'trigger_config.extensions.*' => ['string', 'max:12', 'regex:/^\.?[A-Za-z0-9]+$/'],
            'trigger_config.status_column_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_config.done_values' => ['sometimes', 'nullable', 'array', 'max:50'],
            'trigger_config.done_values.*' => ['string', 'max:120'],
            'trigger_config.match' => ['sometimes', 'nullable', 'array'],
            'trigger_config.match.operator' => ['required_with:trigger_config.match', 'string', Rule::in(self::CHANGE_MATCH_OPERATORS)],
            'trigger_config.match.value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trigger_config.match.values' => ['sometimes', 'nullable', 'array', 'max:50'],
            'trigger_config.match.values.*' => ['nullable', 'string', 'max:255'],
            'conditions' => ['sometimes', 'nullable', 'array', 'max:'.BoardAutomation::MAX_CONDITIONS],
            'conditions.*' => ['array'],
            'conditions.*.column_id' => ['required', 'string', 'max:64'],
            'conditions.*.condition' => ['required', 'string', Rule::in(self::CONDITION_OPERATORS)],
            'conditions.*.value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'conditions.*.values' => ['sometimes', 'nullable', 'array', 'max:50'],
            'conditions.*.values.*' => ['nullable', 'string', 'max:255'],
            ...$this->subitemRuleRules('conditions.*'),
            'condition_operator' => ['sometimes', Rule::in(['and', 'or'])],
            'condition_groups' => ['sometimes', 'nullable', 'array', 'max:'.BoardAutomation::MAX_CONDITION_GROUPS],
            'condition_groups.*' => ['array'],
            'condition_groups.*.join_operator' => ['sometimes', Rule::in(['and', 'or'])],
            'condition_groups.*.rules' => ['required', 'array', 'min:1', 'max:'.BoardAutomation::MAX_CONDITIONS],
            'condition_groups.*.rules.*' => ['array'],
            'condition_groups.*.rules.*.column_id' => ['required', 'string', 'max:64'],
            'condition_groups.*.rules.*.condition' => ['required', 'string', Rule::in(self::CONDITION_OPERATORS)],
            'condition_groups.*.rules.*.value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition_groups.*.rules.*.values' => ['sometimes', 'nullable', 'array', 'max:50'],
            'condition_groups.*.rules.*.values.*' => ['nullable', 'string', 'max:255'],
            ...$this->subitemRuleRules('condition_groups.*.rules.*'),
            // Older clients, already folded into `actions` by `prepareForValidation()`.
            'action_type' => ['sometimes', 'string', Rule::in(BoardAutomation::actionTypes()), new SlackActionHasConnectedWorkspace],
            'action_params' => ['sometimes', 'nullable', 'array'],
            'actions' => [$presence, 'array', 'min:1', 'max:'.BoardAutomation::MAX_ACTIONS],
            'actions.*' => ['array'],
            'actions.*.type' => ['required', 'string', Rule::in(BoardAutomation::actionTypes()), new SlackActionHasConnectedWorkspace],
            'actions.*.params' => ['present', 'array'],
            'else_actions' => ['sometimes', 'nullable', 'array', 'max:'.BoardAutomation::MAX_ACTIONS],
            'else_actions.*' => ['array'],
            'else_actions.*.type' => ['required', 'string', Rule::in(BoardAutomation::actionTypes()), new SlackActionHasConnectedWorkspace],
            'else_actions.*.params' => ['present', 'array'],
        ];
    }

    /**
     * The nested subitem rule of a "subitems" condition, a rule on a subitem column.
     *
     * @return array<string, mixed>
     */
    private function subitemRuleRules(string $prefix): array
    {
        $base_operators = array_values(array_diff(self::CONDITION_OPERATORS, ['all_match', 'any_match', 'none_match']));

        return [
            "{$prefix}.subitem_rule" => ['sometimes', 'nullable', 'array'],
            "{$prefix}.subitem_rule.column_id" => ['required_with:'."{$prefix}.subitem_rule", 'string', 'max:64'],
            "{$prefix}.subitem_rule.condition" => ['required_with:'."{$prefix}.subitem_rule", 'string', Rule::in($base_operators)],
            "{$prefix}.subitem_rule.value" => ['sometimes', 'nullable', 'string', 'max:255'],
            "{$prefix}.subitem_rule.values" => ['sometimes', 'nullable', 'array', 'max:50'],
            "{$prefix}.subitem_rule.values.*" => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The checks that depend on more than one field: the trigger's column and config for its
     * type, each action's params and where cross-board actions point.
     *
     * @param  array{view_id: int|null, board: WorkspaceNavigationItem|null, trigger_type: string|null, trigger_column_id: mixed}  $current
     */
    protected function validateDefinition(Validator $validator, array $current): void
    {
        $view_id = $current['view_id'];
        if ($view_id === null || $validator->errors()->isNotEmpty()) {
            return;
        }

        $trigger_type = $this->input('trigger_type', $current['trigger_type']);
        if ($this->has('trigger_type') || $this->has('trigger_column_id') || $this->has('trigger_config')) {
            $this->validateTrigger($validator, (int) $view_id, (string) $trigger_type);
        }

        if ($this->has('conditions') || $this->has('condition_groups')) {
            $this->validateConditions($validator, (int) $view_id);
        }

        if ($this->has('actions')) {
            foreach ((array) $this->input('actions', []) as $index => $action) {
                $this->validateAction($validator, (int) $view_id, $current['board'], (string) $trigger_type, (int) $index, (array) $action, 'actions');
            }
            $this->validateWaits($validator, 'actions');
        }

        if ($this->filled('else_actions')) {
            if (in_array($trigger_type, BoardAutomation::itemlessTriggers(), true) || $trigger_type === BoardAutomation::TRIGGER_ITEM_SCAN) {
                $validator->errors()->add('else_actions', 'This trigger has no conditions to fail, so it cannot have "Otherwise" actions.');

                return;
            }
            foreach ((array) $this->input('else_actions', []) as $index => $action) {
                $this->validateAction($validator, (int) $view_id, $current['board'], (string) $trigger_type, (int) $index, (array) $action, 'else_actions');
            }
            $this->validateWaits($validator, 'else_actions');
        }
    }

    /**
     * All the "wait" steps of one branch together may not wait longer than {@see BoardAutomation::MAX_WAIT_DAYS}.
     */
    private function validateWaits(Validator $validator, string $branch_key): void
    {
        $minutes = 0;
        foreach ((array) $this->input($branch_key, []) as $action) {
            if (($action['type'] ?? null) !== BoardAutomation::ACTION_WAIT) {
                continue;
            }
            $amount = (int) ($action['params']['amount'] ?? 0);
            $minutes += match ($action['params']['unit'] ?? 'hours') {
                'minutes' => $amount,
                'days' => $amount * 1440,
                default => $amount * 60,
            };
        }

        if ($minutes > self::MAX_WAIT_MINUTES) {
            $validator->errors()->add($branch_key, 'The waits of one automation may add up to '.BoardAutomation::MAX_WAIT_DAYS.' days at most.');
        }
    }

    private function validateTrigger(Validator $validator, int $view_id, string $trigger_type): void
    {
        $column_id = $this->input('trigger_column_id');
        $config = (array) $this->input('trigger_config', []);

        if (in_array($trigger_type, BoardAutomation::columnTriggers(), true)) {
            $column = $column_id ? BoardColumn::where('board_view_id', $view_id)->find((int) $column_id) : null;
            $allowed_types = BoardAutomation::triggerColumnTypes($trigger_type);
            $required_scope = match ($trigger_type) {
                BoardAutomation::TRIGGER_ALL_SUBITEMS_STATUS => BoardColumn::SCOPE_SUBITEM,
                BoardAutomation::TRIGGER_ALL_GROUP_ITEMS_STATUS => BoardColumn::SCOPE_ITEM,
                default => null,
            };

            if (! $column) {
                $validator->errors()->add('trigger_column_id', 'Choose the column this automation watches.');
            } elseif ($allowed_types !== null && ! in_array($column->type, $allowed_types, true)) {
                $validator->errors()->add('trigger_column_id', 'This column cannot be used with this trigger.');
            } elseif ($allowed_types === null && in_array($column->type, BoardColumn::READ_ONLY_TYPES, true)) {
                $validator->errors()->add('trigger_column_id', 'Calculated columns never change on their own, choose another column.');
            } elseif ($required_scope !== null && $column->scope !== $required_scope) {
                $validator->errors()->add('trigger_column_id', $required_scope === BoardColumn::SCOPE_SUBITEM ? 'Choose a subitem status column.' : 'Choose an item status column.');
            } elseif ($required_scope !== null && ! collect($column->config['options'] ?? [])->contains(fn ($option) => (string) ($option['id'] ?? '') === (string) $this->input('trigger_value'))) {
                $validator->errors()->add('trigger_value', 'Choose the label every item must have.');
            }
        }

        if (! empty($config['group_id']) && ! BoardGroup::where('board_view_id', $view_id)->whereKey((int) $config['group_id'])->exists()) {
            $validator->errors()->add('trigger_config.group_id', 'This group does not belong to this table.');
        }

        if ($trigger_type === BoardAutomation::TRIGGER_NUMBER_THRESHOLD && ! is_numeric($config['threshold'] ?? null)) {
            $validator->errors()->add('trigger_config.threshold', 'Enter the number the column must reach.');
        }

        if ($trigger_type === BoardAutomation::TRIGGER_CHECKLIST_ITEM_CHECKED && $this->input('trigger_value') !== null && (! is_string($this->input('trigger_value')) || mb_strlen($this->input('trigger_value')) > 255)) {
            $validator->errors()->add('trigger_value', 'Type the task the trigger waits for, or leave it empty for any task.');
        }

        if ($trigger_type === BoardAutomation::TRIGGER_ITEM_MOVED_TO_BOARD && ! empty($config['from_board_id'])
            && ! WorkspaceNavigationItem::boards()->whereKey((int) $config['from_board_id'])->exists()) {
            $validator->errors()->add('trigger_config.from_board_id', 'Choose a board of this workspace.');
        }

        if ($trigger_type === BoardAutomation::TRIGGER_FORM_SUBMITTED && ! empty($config['form_view_id'])) {
            $board_id = BoardView::whereKey($view_id)->value('board_id');
            if (! BoardView::where('board_id', $board_id)->where('view_type', 'form')->whereKey((int) $config['form_view_id'])->exists()) {
                $validator->errors()->add('trigger_config.form_view_id', 'Choose a form of this board.');
            }
        }

        if ($trigger_type === BoardAutomation::TRIGGER_ITEM_OVERDUE && ! empty($config['status_column_id'])) {
            $status_column = BoardColumn::where('board_view_id', $view_id)
                ->where('scope', BoardColumn::SCOPE_ITEM)
                ->whereIn('type', [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL])
                ->find((int) $config['status_column_id']);
            $option_ids = collect($status_column?->config['options'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();

            if (! $status_column) {
                $validator->errors()->add('trigger_config.status_column_id', 'Choose a status column of this table.');
            } elseif (empty($config['done_values']) || array_diff(array_map('strval', (array) $config['done_values']), $option_ids) !== []) {
                $validator->errors()->add('trigger_config.done_values', 'Choose the labels that mean the item is done.');
            }
        }

        if ($trigger_type === BoardAutomation::TRIGGER_COLUMN_CHANGED && ! empty($config['match']['operator'])) {
            $match = (array) $config['match'];
            if (in_array($match['operator'], ['between'], true) && count((array) ($match['values'] ?? [])) !== 2) {
                $validator->errors()->add('trigger_config.match.values', 'Enter both ends of the range.');
            }
        }

        if (in_array($trigger_type, BoardAutomation::scheduledTriggers(), true)) {
            $schedule = (array) ($config['schedule'] ?? []);
            $frequency = $schedule['frequency'] ?? null;

            if (! in_array($frequency, AutomationSchedule::frequencies(), true)) {
                $validator->errors()->add('trigger_config.schedule.frequency', 'Choose how often this automation runs.');
            } elseif ($frequency === AutomationSchedule::FREQUENCY_WEEKLY && empty($schedule['weekdays'])) {
                $validator->errors()->add('trigger_config.schedule.weekdays', 'Choose at least one day of the week.');
            } elseif ($frequency === AutomationSchedule::FREQUENCY_MONTHLY && empty($schedule['day_of_month'])) {
                $validator->errors()->add('trigger_config.schedule.day_of_month', 'Choose the day of the month.');
            }
        }

        $grouped_rule_count = collect((array) $this->input('condition_groups', []))->sum(fn ($group) => count((array) ($group['rules'] ?? [])));
        if ($trigger_type === BoardAutomation::TRIGGER_ITEM_SCAN && count((array) $this->input('conditions', [])) + $grouped_rule_count < self::MIN_SCAN_CONDITIONS) {
            $validator->errors()->add('conditions', 'Add at least one condition, it decides which items the scheduled check acts on.');
        }

        if (in_array($trigger_type, BoardAutomation::itemlessTriggers(), true)) {
            $first_action_type = $this->input('actions.0.type');
            if ($first_action_type !== null && ! in_array($first_action_type, BoardAutomation::itemlessActions(), true)) {
                $validator->errors()->add('actions.0.type', 'This trigger has no item of its own, start with "create an item", a group action or a notification.');
            }
        }
    }

    private function validateConditions(Validator $validator, int $view_id): void
    {
        $columns = BoardColumn::where('board_view_id', $view_id)->get(['id', 'type', 'scope'])->keyBy(fn (BoardColumn $column) => (string) $column->id);
        $check = function (mixed $condition, string $key) use ($validator, $columns) {
            $field_id = (string) (is_array($condition) ? ($condition['column_id'] ?? '') : '');
            if (! $columns->has($field_id) && ! in_array($field_id, AutomationConditionEvaluator::VIRTUAL_FIELDS, true)) {
                $validator->errors()->add("{$key}.column_id", 'This column does not belong to this table.');

                return;
            }

            if ($field_id === AutomationConditionEvaluator::SUBITEMS_FIELD) {
                $nested = (array) ($condition['subitem_rule'] ?? []);
                $nested_column = $columns->get((string) ($nested['column_id'] ?? ''));
                if (! in_array($condition['condition'] ?? '', ['all_match', 'any_match', 'none_match'], true)) {
                    $validator->errors()->add("{$key}.condition", 'Choose whether all, any or no subitem must match.');
                } elseif (! $nested_column || $nested_column->scope !== BoardColumn::SCOPE_SUBITEM) {
                    $validator->errors()->add("{$key}.subitem_rule.column_id", 'Choose a subitem column.');
                }
            }
        };

        $total = 0;
        foreach ((array) $this->input('conditions', []) as $index => $condition) {
            $check($condition, "conditions.{$index}");
            $total++;
        }
        foreach ((array) $this->input('condition_groups', []) as $group_index => $group) {
            foreach ((array) ($group['rules'] ?? []) as $index => $condition) {
                $check($condition, "condition_groups.{$group_index}.rules.{$index}");
                $total++;
            }
        }

        if ($total > BoardAutomation::MAX_CONDITIONS) {
            $validator->errors()->add('conditions', 'An automation may have '.BoardAutomation::MAX_CONDITIONS.' conditions at most, groups included.');
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function validateAction(Validator $validator, int $view_id, ?WorkspaceNavigationItem $board, string $trigger_type, int $index, array $action, string $branch_key = 'actions'): void
    {
        $type = (string) ($action['type'] ?? '');
        $params = (array) ($action['params'] ?? []);
        $prefix = $this->uses_legacy_action && $branch_key === 'actions' ? 'action_params' : "{$branch_key}.{$index}.params";

        $column_in_view = fn (array $types = []) => Rule::exists('board_columns', 'id')->where(function ($query) use ($view_id, $types) {
            $query->where('board_view_id', $view_id)->whereNotIn('type', BoardColumn::READ_ONLY_TYPES);
            if ($types !== []) {
                $query->whereIn('type', $types);
            }
        });
        $group_in_view = Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id));
        $column_in_scope = fn (string $scope) => Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('scope', $scope)->whereNotIn('type', BoardColumn::READ_ONLY_TYPES));
        $recipient_rules = [
            'notify_user_id' => ['required_without:notify_from_people_column_id', 'nullable', 'integer', Rule::exists('users', 'id')],
            'notify_from_people_column_id' => ['required_without:notify_user_id', 'nullable', 'integer', $column_in_view([BoardColumn::TYPE_PEOPLE])],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];

        $rules = match ($type) {
            BoardAutomation::ACTION_MOVE_TO_GROUP => ['target_group_id' => ['required', 'integer', $group_in_view]],
            BoardAutomation::ACTION_MOVE_TO_BOARD => ['target_board_id' => ['required', 'integer'], 'target_group_id' => ['required', 'integer']],
            BoardAutomation::ACTION_CREATE_ITEM => [
                'target_board_id' => ['sometimes', 'nullable', 'integer'],
                'target_group_id' => ['required', 'integer'],
                'item_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'copy_values' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_CREATE_SUBITEM => [
                'subitem_names' => ['required', 'array', 'min:1', 'max:'.self::MAX_SUBITEM_NAMES],
                'subitem_names.*' => ['required', 'string', 'max:255'],
            ],
            BoardAutomation::ACTION_DUPLICATE_ITEM => ['with_subitems' => ['sometimes', 'boolean']],
            BoardAutomation::ACTION_SET_COLUMN_VALUE => ['target_column_id' => ['required', 'integer', $column_in_view()], 'value' => ['present', 'nullable']],
            BoardAutomation::ACTION_CLEAR_COLUMN => ['target_column_id' => ['required', 'integer', $column_in_view()]],
            BoardAutomation::ACTION_ASSIGN_PERSON => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_PEOPLE])],
                'assign_mode' => ['required', Rule::in(['user', 'creator', 'actor'])],
                'user_id' => ['required_if:assign_mode,user', 'nullable', 'integer', Rule::exists('users', 'id')],
                'replace' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_UNASSIGN_PEOPLE => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_PEOPLE])],
                'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            ],
            BoardAutomation::ACTION_SET_DATE => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE])],
                'offset_days' => ['required', 'integer', 'between:-3650,3650'],
                'use_working_days' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_ADJUST_NUMBER => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS])],
                'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000000,1000000000'],
            ],
            BoardAutomation::ACTION_NOTIFY_PERSON, BoardAutomation::ACTION_SLACK_NOTIFY_PERSON => $recipient_rules,
            BoardAutomation::ACTION_SEND_EMAIL => [
                'notify_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
                'notify_from_people_column_id' => ['sometimes', 'nullable', 'integer', $column_in_view([BoardColumn::TYPE_PEOPLE])],
                'email_column_id' => ['sometimes', 'nullable', 'integer', $column_in_view([BoardColumn::TYPE_EMAIL])],
                'email_addresses' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_EMAIL_ADDRESSES],
                'email_addresses.*' => ['required', 'email', 'max:255'],
                'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'subject' => ['sometimes', 'nullable', 'string', 'max:150'],
            ],
            BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL => [
                'slack_channel_id' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{2,}$/'],
                'slack_channel_name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            BoardAutomation::ACTION_POST_UPDATE => ['message' => ['required', 'string', 'max:2000']],
            BoardAutomation::ACTION_SHIFT_DATE => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE])],
                'amount' => ['required', 'integer', 'not_in:0', 'between:-3650,3650'],
                'unit' => ['required', Rule::in(['days', 'weeks', 'months'])],
                'use_working_days' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_SET_DATE_FROM_COLUMN => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE])],
                'source_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE])],
                'offset_days' => ['sometimes', 'integer', 'between:-3650,3650'],
                'number_column_id' => ['sometimes', 'nullable', 'integer', $column_in_view([BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS])],
                'number_sign' => ['sometimes', 'integer', Rule::in([1, -1])],
            ],
            BoardAutomation::ACTION_ENSURE_DATE_AFTER => [
                'target_column_id' => ['required', 'integer', 'different:source_column_id', $column_in_view([BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE])],
                'source_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE])],
                'gap_days' => ['sometimes', 'integer', 'between:0,365'],
            ],
            BoardAutomation::ACTION_SET_TIMELINE => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_TIMELINE])],
                'start_offset_days' => ['sometimes', 'integer', 'between:-3650,3650'],
                'duration_days' => ['required', 'integer', 'between:1,3650'],
                'use_working_days' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_CREATE_GROUP => [
                'group_name' => ['required', 'string', 'max:255'],
                'position' => ['sometimes', Rule::in(['top', 'bottom'])],
                'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ],
            BoardAutomation::ACTION_DUPLICATE_GROUP => [
                'from_item_group' => ['sometimes', 'boolean'],
                'source_group_id' => ['required_unless:from_item_group,true', 'nullable', 'integer', $group_in_view],
                'group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'with_items' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_ARCHIVE_GROUP => [
                'from_item_group' => ['sometimes', 'boolean'],
                'target_group_id' => ['required_unless:from_item_group,true', 'nullable', 'integer', $group_in_view],
            ],
            BoardAutomation::ACTION_COPY_COLUMN_VALUE => [
                'source_column_id' => ['required', 'integer', 'different:target_column_id', $column_in_view()],
                'target_column_id' => ['required', 'integer', $column_in_view()],
            ],
            BoardAutomation::ACTION_TIME_TRACKING => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_TIME_TRACKING])],
                'mode' => ['required', Rule::in(['start', 'stop'])],
            ],
            BoardAutomation::ACTION_CONNECT_ITEMS => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_CONNECT_BOARD])],
                'match_column_id' => ['required', 'string', 'max:32'],
                'linked_match_column_id' => ['required', 'string', 'max:32'],
                'replace' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_NOTIFY_TEAM => [
                'team_id' => ['required', 'integer', Rule::exists(AccountTeam::class, 'id')->whereNull('deleted_at')],
                'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            BoardAutomation::ACTION_SEND_WEBHOOK => [
                'url' => ['required', 'string', 'max:2000'],
                'secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            BoardAutomation::ACTION_WAIT => [
                'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_WAIT_MINUTES],
                'unit' => ['required', Rule::in(['minutes', 'hours', 'days'])],
                'recheck_conditions' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_SHIFT_DEPENDENTS => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE])],
                'dependency_column_id' => ['required', 'integer', Rule::exists('board_columns', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id)->where('type', BoardColumn::TYPE_DEPENDENCY))],
                'mode' => ['required', Rule::in(['strict', 'flexible'])],
                'use_working_days' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_ASSIGN_ROUND_ROBIN => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_PEOPLE])],
                'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROTATION_PEOPLE],
                'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
                'strategy' => ['required', Rule::in(['rotation', 'least_busy'])],
                'replace' => ['sometimes', 'boolean'],
            ],
            BoardAutomation::ACTION_SET_SUBITEMS_VALUE => [
                'target_column_id' => ['required', 'integer', $column_in_scope(BoardColumn::SCOPE_SUBITEM)],
                'value' => ['present', 'nullable'],
            ],
            BoardAutomation::ACTION_SET_PARENT_VALUE => [
                'target_column_id' => ['required', 'integer', $column_in_scope(BoardColumn::SCOPE_ITEM)],
                'value' => ['present', 'nullable'],
            ],
            BoardAutomation::ACTION_ADD_CHECKLIST_ITEMS => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_CHECKLIST])],
                'tasks' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHECKLIST_TASKS],
                'tasks.*' => ['required', 'string', 'max:250'],
            ],
            BoardAutomation::ACTION_RENAME_ITEM => ['name_template' => ['required', 'string', 'max:255']],
            BoardAutomation::ACTION_CHANGE_VALUES => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_TAGS, BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE])],
                'mode' => ['required', Rule::in(['add', 'remove'])],
                'values' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHANGED_VALUES],
                'values.*' => ['required', 'string', 'max:120'],
            ],
            BoardAutomation::ACTION_UPDATE_CONNECTED_ITEMS => [
                'connect_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_CONNECT_BOARD])],
                'linked_column_id' => ['required', 'integer'],
                'value' => ['present', 'nullable'],
            ],
            BoardAutomation::ACTION_GROUP_ITEMS => [
                'from_item_group' => ['sometimes', 'boolean'],
                'target_group_id' => ['required_unless:from_item_group,true', 'nullable', 'integer', $group_in_view],
                'operation' => ['required', Rule::in(['set_column_value', 'clear_column', 'archive', 'move_to_group'])],
                'target_column_id' => ['required_if:operation,set_column_value,clear_column', 'nullable', 'integer', $column_in_scope(BoardColumn::SCOPE_ITEM)],
                'value' => ['required_if:operation,set_column_value', 'nullable'],
                'destination_group_id' => ['required_if:operation,move_to_group', 'nullable', 'integer', $group_in_view],
            ],
            default => [],
        };
        if ($type === BoardAutomation::ACTION_CREATE_ITEM) {
            $rules += [
                'link_column_id' => ['sometimes', 'nullable', 'integer', $column_in_view([BoardColumn::TYPE_CONNECT_BOARD])],
                'field_mappings' => ['sometimes', 'array', 'max:'.self::MAX_FIELD_MAPPINGS],
                'field_mappings.*.column_id' => ['required', 'integer', $column_in_view()],
                'field_mappings.*.source' => ['required', 'string', 'max:500'],
            ];
        }

        $param_validator = ValidatorFacade::make($params, $rules);
        foreach ($param_validator->errors()->messages() as $key => $messages) {
            $validator->errors()->add("{$prefix}.{$key}", $messages[0]);
        }
        if ($param_validator->errors()->isNotEmpty()) {
            return;
        }

        $is_itemless_trigger = in_array($trigger_type, BoardAutomation::itemlessTriggers(), true);
        $has_created_item = collect((array) $this->input($branch_key, []))->take($index)->contains(fn ($earlier) => ($earlier['type'] ?? null) === BoardAutomation::ACTION_CREATE_ITEM);

        if ($is_itemless_trigger && $index > 0 && ! in_array($type, BoardAutomation::itemlessActions(), true) && ! $has_created_item) {
            $validator->errors()->add("{$branch_key}.{$index}.type", 'This action needs an item, add "create an item" before it.');
        }
        if ($type === BoardAutomation::ACTION_SEND_EMAIL && empty($params['notify_user_id']) && empty($params['notify_from_people_column_id'])
            && empty($params['email_column_id']) && empty($params['email_addresses'])) {
            $validator->errors()->add("{$prefix}.notify_user_id", 'Choose who the email goes to.');
        }
        if ($is_itemless_trigger && ! $has_created_item && ! empty($params['from_item_group'])) {
            $validator->errors()->add("{$prefix}.from_item_group", 'There is no item yet, choose the group instead.');
        }

        $this->validateNewActionParams($validator, $view_id, $type, $params, $prefix);

        $is_cross_board = $type === BoardAutomation::ACTION_MOVE_TO_BOARD
            || ($type === BoardAutomation::ACTION_CREATE_ITEM && ! empty($params['target_board_id']) && (int) $params['target_board_id'] !== $board?->id);

        if ($type === BoardAutomation::ACTION_CREATE_ITEM && ! $is_cross_board
            && ! BoardGroup::where('board_view_id', $view_id)->whereKey((int) $params['target_group_id'])->exists()) {
            $validator->errors()->add("{$prefix}.target_group_id", 'This group does not belong to this table.');
        }

        if ($is_cross_board) {
            $this->validateCrossBoardTarget($validator, $board, $prefix, $params);
        }
    }

    /**
     * The checks of the date, column, connect and webhook actions that a plain rule cannot express.
     *
     * @param  array<string, mixed>  $params
     */
    private function validateNewActionParams(Validator $validator, int $view_id, string $type, array $params, string $prefix): void
    {
        if ($type === BoardAutomation::ACTION_SEND_WEBHOOK && ($problem = OutboundWebhookUrl::problem((string) ($params['url'] ?? '')))) {
            $validator->errors()->add("{$prefix}.url", $problem);
        }

        if ($type === BoardAutomation::ACTION_UPDATE_CONNECTED_ITEMS) {
            $connect_column = BoardColumn::where('board_view_id', $view_id)->find((int) $params['connect_column_id']);
            $linked_board_id = (int) ($connect_column?->config['linked_board_id'] ?? 0);
            $linked_column = $linked_board_id === 0 ? null : BoardColumn::where('board_id', $linked_board_id)->where('scope', BoardColumn::SCOPE_ITEM)->find((int) $params['linked_column_id']);

            if ($linked_board_id === 0) {
                $validator->errors()->add("{$prefix}.connect_column_id", 'Connect this column to a board first.');
            } elseif (! $linked_column) {
                $validator->errors()->add("{$prefix}.linked_column_id", 'Choose a column of the connected board.');
            } elseif (in_array($linked_column->type, BoardColumn::READ_ONLY_TYPES, true)) {
                $validator->errors()->add("{$prefix}.linked_column_id", 'Calculated columns cannot be changed.');
            }
        }

        if ($type === BoardAutomation::ACTION_CREATE_ITEM && ! empty($params['link_column_id'])) {
            $link_column = BoardColumn::where('board_view_id', $view_id)->find((int) $params['link_column_id']);
            if (empty($params['target_board_id']) || (int) ($link_column?->config['linked_board_id'] ?? 0) !== (int) $params['target_board_id']) {
                $validator->errors()->add("{$prefix}.link_column_id", 'Choose a connect boards column that links to the board the item is created on.');
            }
        }

        if ($type === BoardAutomation::ACTION_CONNECT_ITEMS) {
            $column = BoardColumn::where('board_view_id', $view_id)->find((int) $params['target_column_id']);
            $linked_board_id = (int) ($column?->config['linked_board_id'] ?? 0);
            $match = (string) $params['match_column_id'];
            $linked_match = (string) $params['linked_match_column_id'];

            if ($linked_board_id === 0) {
                $validator->errors()->add("{$prefix}.target_column_id", 'Connect this column to a board first.');
            }
            if ($match !== 'name' && ! BoardColumn::where('board_view_id', $view_id)->whereKey((int) $match)->exists()) {
                $validator->errors()->add("{$prefix}.match_column_id", 'Choose a column of this table.');
            }
            if ($linked_match !== 'name' && ! BoardColumn::where('board_id', $linked_board_id)->whereKey((int) $linked_match)->exists()) {
                $validator->errors()->add("{$prefix}.linked_match_column_id", 'Choose a column of the connected board.');
            }
        }
    }

    /**
     * A cross-board action must point at a table of another board's primary tab in the same
     * workspace, which the person saving the automation is allowed to edit.
     *
     * @param  array<string, mixed>  $params
     */
    private function validateCrossBoardTarget(Validator $validator, ?WorkspaceNavigationItem $board, string $prefix, array $params): void
    {
        $target_board = WorkspaceNavigationItem::boards()->notArchived()->find((int) ($params['target_board_id'] ?? 0));
        $user = $this->user();

        if (! $target_board || ! $board || $target_board->id === $board->id || $target_board->workspace_id !== $board->workspace_id) {
            $validator->errors()->add("{$prefix}.target_board_id", 'Choose another board of this workspace.');

            return;
        }
        if (! $user instanceof User || ! BoardEditGate::allowsContent($target_board, $user)) {
            $validator->errors()->add("{$prefix}.target_board_id", 'You cannot add items to this board.');

            return;
        }

        $group_exists = BoardGroup::where('board_id', $target_board->id)
            ->whereHas('boardView', fn ($query) => $query->where('is_primary', true))
            ->whereKey((int) ($params['target_group_id'] ?? 0))
            ->exists();
        if (! $group_exists) {
            $validator->errors()->add("{$prefix}.target_group_id", 'This group does not belong to the chosen board.');
        }
    }
}
