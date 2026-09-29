<?php

namespace App\Services\Board;

use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Collection;

/**
 * Finds what an automation uses that no longer exists: the column its trigger watches, a column a
 * condition reads, the column, group, board, person or team an action points at. Each problem has
 * the `path` of the sentence part it belongs to (`trigger`, `conditions.1`, `condition_groups.0`,
 * `actions.0`, `else_actions.0`), so the
 * Manage tab and the builder can mark it, and a message that says what to fix.
 *
 * Bound per request (scoped): the tab's columns and groups are read once per tab and reused for
 * every automation checked in the same request.
 */
class BoardAutomationHealthChecker
{
    /** Condition fields that are item details, not columns, see `AutomationConditionEvaluator`. */
    private const VIRTUAL_FIELDS = AutomationConditionEvaluator::VIRTUAL_FIELDS;

    /** Action params that hold a column id of the automation's own tab. */
    private const COLUMN_PARAMS = [
        'target_column_id', 'source_column_id', 'number_column_id', 'notify_from_people_column_id', 'dependency_column_id',
        'email_column_id', 'connect_column_id', 'link_column_id',
    ];

    /** @var array<int, Collection<int, BoardColumn>> */
    private array $columns_by_view = [];

    /** @var array<int, Collection<int, BoardGroup>> */
    private array $groups_by_view = [];

    /**
     * @return array<int, array{path: string, message: string}>
     */
    public function problems(BoardAutomation $automation, bool $fresh = false): array
    {
        $view_id = (int) $automation->board_view_id;
        if ($fresh) {
            unset($this->columns_by_view[$view_id], $this->groups_by_view[$view_id]);
        }

        $columns = $this->columns_by_view[$view_id] ??= BoardColumn::where('board_view_id', $view_id)->get(['id', 'type', 'label', 'scope', 'config'])->keyBy('id');
        $groups = $this->groups_by_view[$view_id] ??= BoardGroup::where('board_view_id', $view_id)->get(['id', 'name', 'is_archived'])->keyBy('id');

        return [
            ...$this->triggerProblems($automation, $columns, $groups),
            ...$this->conditionProblems($automation, $columns),
            ...$this->actionProblems($automation, $columns, $groups),
        ];
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardGroup>  $groups
     * @return array<int, array{path: string, message: string}>
     */
    private function triggerProblems(BoardAutomation $automation, Collection $columns, Collection $groups): array
    {
        $problems = [];
        $config = (array) ($automation->trigger_config ?? []);

        if (in_array($automation->trigger_type, BoardAutomation::columnTriggers(), true)) {
            $column = $columns->get((int) $automation->trigger_column_id);
            $types = BoardAutomation::triggerColumnTypes($automation->trigger_type);
            if (! $column) {
                $problems[] = $this->problem('trigger', 'The column the trigger watches was deleted.');
            } elseif ($types !== null && ! in_array($column->type, $types, true)) {
                $problems[] = $this->problem('trigger', "\"{$column->label}\" changed type and no longer fits the trigger.");
            }
        }

        if (! empty($config['group_id']) && ! $groups->has((int) $config['group_id'])) {
            $problems[] = $this->problem('trigger', 'The group the trigger watches was deleted.');
        }

        if (! empty($config['status_column_id']) && ! $columns->has((int) $config['status_column_id'])) {
            $problems[] = $this->problem('trigger', 'The status column that says an item is done was deleted.');
        }

        if ($automation->trigger_type === BoardAutomation::TRIGGER_FORM_SUBMITTED && ! empty($config['form_view_id'])
            && ! BoardView::where('board_id', $automation->board_id)->whereKey((int) $config['form_view_id'])->exists()) {
            $problems[] = $this->problem('trigger', 'The form the trigger watches was deleted.');
        }

        if ($automation->trigger_type === BoardAutomation::TRIGGER_ITEM_MOVED_TO_BOARD && ! empty($config['from_board_id'])
            && ! WorkspaceNavigationItem::boards()->whereKey((int) $config['from_board_id'])->exists()) {
            $problems[] = $this->problem('trigger', 'The board items come from was deleted.');
        }

        return $problems;
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @return array<int, array{path: string, message: string}>
     */
    private function conditionProblems(BoardAutomation $automation, Collection $columns): array
    {
        $problems = [];
        $is_missing = fn (mixed $condition): bool => $this->ruleIsMissingColumn($condition, $columns);

        foreach (array_values((array) ($automation->conditions ?? [])) as $index => $condition) {
            if ($is_missing($condition)) {
                $problems[] = $this->problem("conditions.{$index}", 'A column a condition reads was deleted.');
            }
        }
        foreach (array_values((array) ($automation->condition_groups ?? [])) as $index => $group) {
            foreach ((array) ($group['rules'] ?? []) as $condition) {
                if ($is_missing($condition)) {
                    $problems[] = $this->problem("condition_groups.{$index}", 'A column a condition reads was deleted.');
                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardGroup>  $groups
     * @return array<int, array{path: string, message: string}>
     */
    private function actionProblems(BoardAutomation $automation, Collection $columns, Collection $groups): array
    {
        $problems = [];
        $branches = ['actions' => $automation->resolvedActions(), 'else_actions' => $automation->resolvedElseActions()];

        foreach ($branches as $branch_key => $branch_actions) {
            foreach ($branch_actions as $index => $action) {
                array_push($problems, ...$this->oneActionProblems($automation, $columns, $groups, $action, "{$branch_key}.{$index}"));
            }
        }

        return $problems;
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardGroup>  $groups
     * @param  array{type: string, params: array<string, mixed>}  $action
     * @return array<int, array{path: string, message: string}>
     */
    private function oneActionProblems(BoardAutomation $automation, Collection $columns, Collection $groups, array $action, string $path): array
    {
        $problems = [];

        $params = $action['params'];
        $type = $action['type'];

        foreach (self::COLUMN_PARAMS as $key) {
            if (! empty($params[$key]) && ! $columns->has((int) $params[$key])) {
                $problems[] = $this->problem($path, 'A column this action uses was deleted.');
            }
        }
        if (! empty($params['match_column_id']) && $params['match_column_id'] !== 'name' && ! $columns->has((int) $params['match_column_id'])) {
            $problems[] = $this->problem($path, 'The column this action matches on was deleted.');
        }

        $is_cross_board = ! empty($params['target_board_id']) && (int) $params['target_board_id'] !== (int) $automation->board_id;
        if ($is_cross_board) {
            $board = WorkspaceNavigationItem::boards()->notArchived()->find((int) $params['target_board_id']);
            if (! $board) {
                $problems[] = $this->problem($path, 'The other board this action uses was deleted or archived.');
            } elseif (! empty($params['target_group_id']) && ! BoardGroup::where('board_id', $board->id)->whereKey((int) $params['target_group_id'])->exists()) {
                $problems[] = $this->problem($path, 'The group on the other board was deleted.');
            }
        } else {
            foreach (['target_group_id', 'source_group_id'] as $key) {
                if (! empty($params[$key]) && empty($params['from_item_group']) && ! $groups->has((int) $params[$key])) {
                    $problems[] = $this->problem($path, 'The group this action uses was deleted.');
                }
            }
        }

        if (! empty($params['destination_group_id']) && ! $groups->has((int) $params['destination_group_id'])) {
            $problems[] = $this->problem($path, 'The group this action moves items to was deleted.');
        }

        $user_ids = array_filter([$params['notify_user_id'] ?? null, ($params['assign_mode'] ?? 'user') === 'user' && $type === BoardAutomation::ACTION_ASSIGN_PERSON ? ($params['user_id'] ?? null) : null]);
        foreach ($user_ids as $user_id) {
            if (! User::whereKey((int) $user_id)->where('is_active', true)->exists()) {
                $problems[] = $this->problem($path, 'The person this action reaches was deactivated or removed.');
            }
        }

        if ($type === BoardAutomation::ACTION_UPDATE_CONNECTED_ITEMS && ! BoardColumn::whereKey((int) ($params['linked_column_id'] ?? 0))->exists()) {
            $problems[] = $this->problem($path, 'The column of the connected board this action sets was deleted.');
        }

        if ($type === BoardAutomation::ACTION_NOTIFY_TEAM && ! AccountTeam::whereKey((int) ($params['team_id'] ?? 0))->exists()) {
            $problems[] = $this->problem($path, 'The team this action notifies was deleted.');
        }
        if ($type !== BoardAutomation::ACTION_NOTIFY_TEAM && ! empty($params['team_id']) && ! AccountTeam::whereKey((int) $params['team_id'])->exists()) {
            $problems[] = $this->problem($path, 'The team this action reaches was deleted.');
        }

        $dynamic_column_id = is_array($params['dynamic_value'] ?? null) ? ($params['dynamic_value']['column_id'] ?? null) : null;
        if (! empty($dynamic_column_id) && ! $columns->has((int) $dynamic_column_id)) {
            $problems[] = $this->problem($path, 'The column this action reads its value from was deleted.');
        }

        foreach ((array) ($params['column_ids'] ?? []) as $column_id) {
            if (! $columns->has((int) $column_id)) {
                $problems[] = $this->problem($path, 'A column the digest shows was deleted.');
                break;
            }
        }
        foreach ((array) ($params['digest_rules'] ?? []) as $rule) {
            if ($this->ruleIsMissingColumn($rule, $columns)) {
                $problems[] = $this->problem($path, 'A column the digest filters on was deleted.');
                break;
            }
        }

        $rotation = array_map('intval', (array) ($params['user_ids'] ?? []));
        if ($type === BoardAutomation::ACTION_ASSIGN_ROUND_ROBIN && $rotation !== [] && ! User::whereIn('id', $rotation)->where('is_active', true)->exists()) {
            $problems[] = $this->problem($path, 'Nobody in the rotation is still active.');
        }

        return $problems;
    }

    /**
     * Whether a condition or filter rule reads a column that no longer exists: its own field, the
     * field of its nested subitem rule, or the column its dynamic value is read from.
     *
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function ruleIsMissingColumn(mixed $rule, Collection $columns): bool
    {
        if (! is_array($rule)) {
            return false;
        }

        $field_id = (string) ($rule['column_id'] ?? '');
        if (! in_array($field_id, self::VIRTUAL_FIELDS, true) && ! $columns->has((int) $field_id)) {
            return true;
        }

        $nested = $rule['subitem_rule'] ?? null;
        if (is_array($nested) && ! in_array((string) ($nested['column_id'] ?? ''), self::VIRTUAL_FIELDS, true) && ! $columns->has((int) ($nested['column_id'] ?? 0))) {
            return true;
        }

        $dynamic = $rule['dynamic'] ?? null;

        return is_array($dynamic) && ($dynamic['source'] ?? null) === 'column' && ! $columns->has((int) ($dynamic['column_id'] ?? 0));
    }

    /**
     * @return array{path: string, message: string}
     */
    private function problem(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
