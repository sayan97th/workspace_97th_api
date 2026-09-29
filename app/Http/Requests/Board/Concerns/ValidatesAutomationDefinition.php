<?php

namespace App\Http\Requests\Board\Concerns;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Rules\SlackActionHasConnectedWorkspace;
use App\Support\AutomationSchedule;
use App\Support\BoardEditGate;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by creating and editing an automation with the sentence builder: the
 * trigger (with its column, value and `trigger_config`), the "and only if" `conditions` and the
 * ordered `actions`, each checked against the params its type needs.
 *
 * Older clients send a single `action_type` + `action_params`, which is read as a list of one.
 */
trait ValidatesAutomationDefinition
{
    /** Operators a condition may use, the same ones the board's Advanced filters offer. */
    private const CONDITION_OPERATORS = [
        'is', 'is_not', 'contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'not_equals',
        'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'between', 'before', 'after',
        'on_or_before', 'on_or_after', 'is_checked', 'is_unchecked', 'is_empty', 'is_not_empty',
    ];

    /** Virtual condition fields that are not columns, see `BoardItemFilterEvaluator`. */
    private const CONDITION_VIRTUAL_FIELDS = ['name', '__group__', '__created_by__', '__created_at__', '__updated_at__', '__starred__'];

    private const MAX_SUBITEM_NAMES = 20;

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
            'trigger_type' => [$presence, 'string', Rule::in(BoardAutomation::triggerTypes())],
            'trigger_column_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_value' => ['sometimes', 'nullable'],
            'trigger_config' => ['sometimes', 'nullable', 'array'],
            'trigger_config.from_value' => ['sometimes', 'nullable', 'string', 'max:120'],
            'trigger_config.offset_days' => ['sometimes', 'nullable', 'integer', 'between:-365,365'],
            'trigger_config.time' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'trigger_config.timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'trigger_config.group_id' => ['sometimes', 'nullable', 'integer'],
            'trigger_config.schedule' => ['sometimes', 'nullable', 'array'],
            'trigger_config.schedule.frequency' => ['sometimes', 'string', Rule::in(AutomationSchedule::frequencies())],
            'trigger_config.schedule.weekdays' => ['sometimes', 'array', 'max:7'],
            'trigger_config.schedule.weekdays.*' => ['integer', 'between:1,7'],
            'trigger_config.schedule.day_of_month' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'trigger_config.schedule.time' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'trigger_config.schedule.timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'conditions' => ['sometimes', 'nullable', 'array', 'max:'.BoardAutomation::MAX_CONDITIONS],
            'conditions.*' => ['array'],
            'conditions.*.column_id' => ['required', 'string', 'max:64'],
            'conditions.*.condition' => ['required', 'string', Rule::in(self::CONDITION_OPERATORS)],
            'conditions.*.value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'conditions.*.values' => ['sometimes', 'nullable', 'array', 'max:50'],
            'conditions.*.values.*' => ['nullable', 'string', 'max:255'],
            // Older clients, already folded into `actions` by `prepareForValidation()`.
            'action_type' => ['sometimes', 'string', Rule::in(BoardAutomation::actionTypes()), new SlackActionHasConnectedWorkspace],
            'action_params' => ['sometimes', 'nullable', 'array'],
            'actions' => [$presence, 'array', 'min:1', 'max:'.BoardAutomation::MAX_ACTIONS],
            'actions.*' => ['array'],
            'actions.*.type' => ['required', 'string', Rule::in(BoardAutomation::actionTypes()), new SlackActionHasConnectedWorkspace],
            'actions.*.params' => ['present', 'array'],
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

        if ($this->has('conditions')) {
            $this->validateConditions($validator, (int) $view_id);
        }

        if ($this->has('actions')) {
            foreach ((array) $this->input('actions', []) as $index => $action) {
                $this->validateAction($validator, (int) $view_id, $current['board'], (string) $trigger_type, (int) $index, (array) $action);
            }
        }
    }

    private function validateTrigger(Validator $validator, int $view_id, string $trigger_type): void
    {
        $column_id = $this->input('trigger_column_id');
        $config = (array) $this->input('trigger_config', []);

        if (in_array($trigger_type, BoardAutomation::columnTriggers(), true)) {
            $column = $column_id ? BoardColumn::where('board_view_id', $view_id)->find((int) $column_id) : null;
            $allowed_types = match ($trigger_type) {
                BoardAutomation::TRIGGER_STATUS_CHANGED => [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL],
                BoardAutomation::TRIGGER_DATE_ARRIVED => [BoardColumn::TYPE_DATE],
                BoardAutomation::TRIGGER_PERSON_ASSIGNED => [BoardColumn::TYPE_PEOPLE],
                default => null,
            };

            if (! $column) {
                $validator->errors()->add('trigger_column_id', 'Choose the column this automation watches.');
            } elseif ($allowed_types !== null && ! in_array($column->type, $allowed_types, true)) {
                $validator->errors()->add('trigger_column_id', 'This column cannot be used with this trigger.');
            } elseif ($allowed_types === null && in_array($column->type, BoardColumn::READ_ONLY_TYPES, true)) {
                $validator->errors()->add('trigger_column_id', 'Calculated columns never change on their own, choose another column.');
            }
        }

        if ($trigger_type === BoardAutomation::TRIGGER_ITEM_MOVED_TO_GROUP && ! empty($config['group_id'])
            && ! BoardGroup::where('board_view_id', $view_id)->whereKey((int) $config['group_id'])->exists()) {
            $validator->errors()->add('trigger_config.group_id', 'This group does not belong to this table.');
        }

        if ($trigger_type === BoardAutomation::TRIGGER_RECURRING) {
            $schedule = (array) ($config['schedule'] ?? []);
            $frequency = $schedule['frequency'] ?? null;

            if (! in_array($frequency, AutomationSchedule::frequencies(), true)) {
                $validator->errors()->add('trigger_config.schedule.frequency', 'Choose how often this automation runs.');
            } elseif ($frequency === AutomationSchedule::FREQUENCY_WEEKLY && empty($schedule['weekdays'])) {
                $validator->errors()->add('trigger_config.schedule.weekdays', 'Choose at least one day of the week.');
            } elseif ($frequency === AutomationSchedule::FREQUENCY_MONTHLY && empty($schedule['day_of_month'])) {
                $validator->errors()->add('trigger_config.schedule.day_of_month', 'Choose the day of the month.');
            }

            $first_action_type = $this->input('actions.0.type');
            if ($first_action_type !== null && ! in_array($first_action_type, BoardAutomation::itemlessActions(), true)) {
                $validator->errors()->add('actions.0.type', 'A recurring automation has no item of its own, start it with "create an item" or a notification.');
            }
        }
    }

    private function validateConditions(Validator $validator, int $view_id): void
    {
        $column_ids = BoardColumn::where('board_view_id', $view_id)->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ((array) $this->input('conditions', []) as $index => $condition) {
            $field_id = (string) ($condition['column_id'] ?? '');
            if (! in_array($field_id, $column_ids, true) && ! in_array($field_id, self::CONDITION_VIRTUAL_FIELDS, true)) {
                $validator->errors()->add("conditions.{$index}.column_id", 'This column does not belong to this table.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function validateAction(Validator $validator, int $view_id, ?WorkspaceNavigationItem $board, string $trigger_type, int $index, array $action): void
    {
        $type = (string) ($action['type'] ?? '');
        $params = (array) ($action['params'] ?? []);
        $prefix = $this->uses_legacy_action ? 'action_params' : "actions.{$index}.params";

        $column_in_view = fn (array $types = []) => Rule::exists('board_columns', 'id')->where(function ($query) use ($view_id, $types) {
            $query->where('board_view_id', $view_id)->whereNotIn('type', BoardColumn::READ_ONLY_TYPES);
            if ($types !== []) {
                $query->whereIn('type', $types);
            }
        });
        $group_in_view = Rule::exists('board_groups', 'id')->where(fn ($query) => $query->where('board_view_id', $view_id));
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
            ],
            BoardAutomation::ACTION_ADJUST_NUMBER => [
                'target_column_id' => ['required', 'integer', $column_in_view([BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS])],
                'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000000,1000000000'],
            ],
            BoardAutomation::ACTION_NOTIFY_PERSON, BoardAutomation::ACTION_SLACK_NOTIFY_PERSON => $recipient_rules,
            BoardAutomation::ACTION_SEND_EMAIL => [...$recipient_rules, 'subject' => ['sometimes', 'nullable', 'string', 'max:150']],
            BoardAutomation::ACTION_SLACK_NOTIFY_CHANNEL => [
                'slack_channel_id' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{2,}$/'],
                'slack_channel_name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            BoardAutomation::ACTION_POST_UPDATE => ['message' => ['required', 'string', 'max:2000']],
            default => [],
        };

        $param_validator = ValidatorFacade::make($params, $rules);
        foreach ($param_validator->errors()->messages() as $key => $messages) {
            $validator->errors()->add("{$prefix}.{$key}", $messages[0]);
        }
        if ($param_validator->errors()->isNotEmpty()) {
            return;
        }

        if ($trigger_type === BoardAutomation::TRIGGER_RECURRING && $index > 0 && ! in_array($type, BoardAutomation::itemlessActions(), true)) {
            $starts_with_create = collect((array) $this->input('actions', []))->take($index)->contains(fn ($earlier) => ($earlier['type'] ?? null) === BoardAutomation::ACTION_CREATE_ITEM);
            if (! $starts_with_create) {
                $validator->errors()->add("actions.{$index}.type", 'This action needs an item, add "create an item" before it.');
            }
        }

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
