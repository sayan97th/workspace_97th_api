<?php

namespace App\Support;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Services\Board\AutomationDynamicValueResolver;

/**
 * Turns one board's automation into a definition any board can start from, for the account wide
 * templates. Everything that only means something on the original board is cleared: column ids
 * (their `{type, scope}` is remembered under the same path in `column_kinds`, so the builder can
 * pick a column of that kind), group ids, form ids and the option ids of status like columns.
 * People, teams, other boards, messages and schedules are kept, they mean the same everywhere.
 */
final class PortableAutomationDefinition
{
    /** Params of an action that hold a column id of the automation's own tab. */
    private const COLUMN_PARAMS = ['target_column_id', 'source_column_id', 'sort_column_id', 'number_column_id', 'notify_from_people_column_id', 'match_column_id', 'dependency_column_id', 'email_column_id', 'connect_column_id', 'link_column_id', 'date_column_id'];

    /** Column types whose values are option ids of that one column. */
    private const OPTION_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_TAGS];

    /**
     * @return array{definition: array<string, mixed>, column_kinds: array<string, array{type: string, scope: string}>}
     */
    public static function fromAutomation(BoardAutomation $automation): array
    {
        $columns = BoardColumn::where('board_view_id', $automation->board_view_id)->get(['id', 'type', 'scope'])->keyBy('id');
        $kinds = [];
        $remember = function (string $path, mixed $column_id) use ($columns, &$kinds): bool {
            $column = is_numeric($column_id) ? $columns->get((int) $column_id) : null;
            if ($column) {
                $kinds[$path] = ['type' => $column->type, 'scope' => $column->scope];
            }

            return $column !== null;
        };
        $is_option_column = fn (mixed $column_id) => is_numeric($column_id) && in_array($columns->get((int) $column_id)?->type, self::OPTION_TYPES, true);

        $trigger_value = $automation->trigger_value;
        if ($is_option_column($automation->trigger_column_id)) {
            $trigger_value = null;
        }
        $remember('trigger_column_id', $automation->trigger_column_id);

        $config = (array) ($automation->trigger_config ?? []);
        // A member's own Gmail or Outlook account means nothing to whoever uses the template.
        unset($config['from_value'], $config['group_id'], $config['form_view_id'], $config['status_column_id'], $config['done_values'], $config['external_account_id'], $config['external_account_email']);
        if (isset($config['match']) && $is_option_column($automation->trigger_column_id)) {
            $config['match']['values'] = [];
        }

        $portable_rules = function (array $rules, string $path) use ($remember, $is_option_column): array {
            $portable = [];
            foreach (array_values($rules) as $index => $condition) {
                $column_id = (string) ($condition['column_id'] ?? '');
                $is_column = $remember("{$path}.{$index}.column_id", $column_id);
                $clears_values = $is_option_column($column_id) || $column_id === '__group__';
                $portable[] = [
                    'column_id' => $is_column ? '' : $column_id,
                    'condition' => (string) ($condition['condition'] ?? ''),
                    'value' => $clears_values ? '' : (string) ($condition['value'] ?? ''),
                    'values' => $clears_values ? [] : array_values((array) ($condition['values'] ?? [])),
                    // A dynamic value travels unless it reads a column of this board, that one is picked again.
                    ...(AutomationDynamicValueResolver::isDynamic($condition['dynamic'] ?? null) && $condition['dynamic']['source'] !== 'column' ? ['dynamic' => $condition['dynamic']] : []),
                ];
            }

            return $portable;
        };

        $conditions = $portable_rules(array_filter((array) ($automation->conditions ?? []), 'is_array'), 'conditions');
        $condition_groups = [];
        foreach (array_values(array_filter((array) ($automation->condition_groups ?? []), 'is_array')) as $group_index => $group) {
            $condition_groups[] = [
                'join_operator' => ($group['join_operator'] ?? 'and') === 'or' ? 'or' : 'and',
                'rules' => $portable_rules(array_filter((array) ($group['rules'] ?? []), 'is_array'), "condition_groups.{$group_index}.rules"),
            ];
        }

        $portable_actions = function (array $list, string $branch_key) use ($automation, $remember, $is_option_column): array {
            $actions = [];
            foreach ($list as $index => $action) {
                $params = $action['params'];
                $is_cross_board = ! empty($params['target_board_id']) && (int) $params['target_board_id'] !== (int) $automation->board_id;

                $has_option_value = in_array($action['type'], [BoardAutomation::ACTION_SET_COLUMN_VALUE, BoardAutomation::ACTION_SET_SUBITEMS_VALUE, BoardAutomation::ACTION_SET_PARENT_VALUE], true);
                if ($has_option_value && $is_option_column($params['target_column_id'] ?? null)) {
                    unset($params['value']);
                }
                foreach (self::COLUMN_PARAMS as $key) {
                    if (isset($params[$key]) && $params[$key] !== 'name' && $remember("{$branch_key}.{$index}.params.{$key}", $params[$key])) {
                        unset($params[$key]);
                    }
                }
                foreach ((array) ($params['field_mappings'] ?? []) as $mapping_index => $mapping) {
                    if ($remember("{$branch_key}.{$index}.params.field_mappings.{$mapping_index}.column_id", $mapping['column_id'] ?? null)) {
                        $params['field_mappings'][$mapping_index]['column_id'] = null;
                    }
                }
                unset($params['linked_match_column_id'], $params['linked_column_id'], $params['destination_group_id'], $params['external_account_id'], $params['external_account_email'], $params['calendar_id'], $params['calendar_name']);
                if ($action['type'] === BoardAutomation::ACTION_CHANGE_VALUES && $is_option_column($action['params']['target_column_id'] ?? null)) {
                    $params['values'] = [];
                }
                if (! $is_cross_board) {
                    unset($params['target_group_id'], $params['source_group_id']);
                }

                $actions[] = ['type' => $action['type'], 'params' => $params];
            }

            return $actions;
        };
        $actions = $portable_actions($automation->resolvedActions(), 'actions');
        $else_actions = $portable_actions($automation->resolvedElseActions(), 'else_actions');

        return [
            'definition' => [
                'trigger_type' => $automation->trigger_type,
                'trigger_column_id' => null,
                'trigger_value' => $trigger_value,
                'trigger_config' => $config ?: null,
                'conditions' => $conditions,
                'condition_operator' => $automation->condition_operator === 'or' ? 'or' : 'and',
                'condition_groups' => $condition_groups,
                'actions' => $actions,
                'else_actions' => $else_actions,
            ],
            'column_kinds' => $kinds,
        ];
    }
}
