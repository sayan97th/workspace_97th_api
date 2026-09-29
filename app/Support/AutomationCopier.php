<?php

namespace App\Support;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardView;
use Illuminate\Support\Collection;

/**
 * Copies an automation onto another tab, usually the primary tab of another board, rewriting what
 * only means something on the original: columns are matched by type, scope and label (or by type
 * and scope alone when the target has exactly one such column), status like options by label and
 * groups by name. Whatever finds no match is left empty and listed in `unmapped`, so the copy
 * starts switched off and the builder marks those tokens for the user to choose again.
 *
 * An imported automation (see {@see AutomationTransfer}) is not saved anywhere yet: its columns
 * and groups come from the export file instead of the database, passed as `$source_columns` and
 * `$source_groups`.
 */
final class AutomationCopier
{
    /** Params of an action that hold a column id of the automation's own tab. */
    private const COLUMN_PARAMS = [
        'target_column_id', 'source_column_id', 'sort_column_id', 'number_column_id', 'notify_from_people_column_id',
        'dependency_column_id', 'email_column_id', 'connect_column_id', 'link_column_id',
    ];

    private const OPTION_TYPES = [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN, BoardColumn::TYPE_TAGS];

    /** @var Collection<int, BoardColumn> */
    private Collection $source_columns;

    /** @var Collection<int, BoardColumn> */
    private Collection $target_columns;

    /** @var Collection<int, BoardGroup> */
    private Collection $source_groups;

    /** @var Collection<int, BoardGroup> */
    private Collection $target_groups;

    /** @var array<int, string> */
    private array $unmapped = [];

    /**
     * @param  Collection<int, BoardColumn>|null  $source_columns  the original tab's columns keyed by id, read from the database when null
     * @param  Collection<int, BoardGroup>|null  $source_groups  the original tab's groups keyed by id, read from the database when null
     */
    public function __construct(
        private readonly BoardAutomation $automation,
        private readonly BoardView $target_view,
        ?Collection $source_columns = null,
        ?Collection $source_groups = null,
    ) {
        $this->source_columns = $source_columns ?? BoardColumn::where('board_view_id', $automation->board_view_id)->get()->keyBy('id');
        $this->target_columns = BoardColumn::where('board_view_id', $target_view->id)->get()->keyBy('id');
        $this->source_groups = $source_groups ?? BoardGroup::where('board_view_id', $automation->board_view_id)->get()->keyBy('id');
        $this->target_groups = BoardGroup::where('board_view_id', $target_view->id)->where('is_archived', false)->get();
    }

    /**
     * The attributes of the copy, and what could not be matched on the target.
     *
     * @return array{attributes: array<string, mixed>, unmapped: array<int, string>}
     */
    public function copy(): array
    {
        $automation = $this->automation;
        $this->unmapped = [];

        $trigger_column_id = $this->mapColumn($automation->trigger_column_id, 'the trigger column');
        $trigger_value = $automation->trigger_value;
        if ($this->isOptionColumn($automation->trigger_column_id) && $trigger_value !== null) {
            $trigger_value = $this->mapOption($automation->trigger_column_id, $trigger_column_id, $trigger_value);
        }

        $config = (array) ($automation->trigger_config ?? []);
        if (! empty($config['from_value'])) {
            $config['from_value'] = $this->mapOption($automation->trigger_column_id, $trigger_column_id, $config['from_value']);
        }
        if (! empty($config['group_id'])) {
            $config['group_id'] = $this->mapGroup((int) $config['group_id']);
        }
        if (! empty($config['status_column_id'])) {
            $source_status_id = (int) $config['status_column_id'];
            $config['status_column_id'] = $this->mapColumn($source_status_id, 'the status that says an item is done');
            $config['done_values'] = array_values(array_filter(array_map(fn ($id) => $this->mapOption($source_status_id, $config['status_column_id'], $id), (array) ($config['done_values'] ?? []))));
        }
        if (! empty($config['match']['values']) && $this->isOptionColumn($automation->trigger_column_id)) {
            $config['match']['values'] = array_values(array_filter(array_map(fn ($id) => $this->mapOption($automation->trigger_column_id, $trigger_column_id, $id), (array) $config['match']['values'])));
        }
        unset($config['form_view_id']);

        return [
            'attributes' => [
                'trigger_type' => $automation->trigger_type,
                'trigger_column_id' => $trigger_column_id,
                'trigger_value' => $trigger_value,
                'trigger_config' => array_filter($config, fn ($value) => $value !== null) ?: null,
                'conditions' => $this->mapRules((array) ($automation->conditions ?? [])) ?: null,
                'condition_operator' => $automation->condition_operator ?: 'and',
                'condition_groups' => array_map(
                    fn (array $group) => ['join_operator' => $group['join_operator'] ?? 'and', 'rules' => $this->mapRules((array) ($group['rules'] ?? []))],
                    array_values(array_filter((array) ($automation->condition_groups ?? []), 'is_array'))
                ) ?: null,
                'actions' => $this->mapActions($automation->resolvedActions()),
                'else_actions' => $this->mapActions($automation->resolvedElseActions()) ?: null,
            ],
            'unmapped' => array_values(array_unique($this->unmapped)),
        ];
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function mapRules(array $rules): array
    {
        $mapped = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (is_array($rule['dynamic'] ?? null) && ! empty($rule['dynamic']['column_id'])) {
                $rule['dynamic']['column_id'] = $this->mapColumn((int) $rule['dynamic']['column_id'], 'a column a condition compares with');
                if ($rule['dynamic']['column_id'] === null) {
                    continue;
                }
            }
            $field_id = (string) ($rule['column_id'] ?? '');
            if (! is_numeric($field_id)) {
                $rule['values'] = $field_id === '__group__'
                    ? array_values(array_filter(array_map(fn ($id) => $this->mapGroup((int) $id), (array) ($rule['values'] ?? []))))
                    : ($rule['values'] ?? []);
                if (is_array($rule['subitem_rule'] ?? null)) {
                    $nested = $this->mapRules([$rule['subitem_rule']]);
                    if ($nested === []) {
                        continue;
                    }
                    $rule['subitem_rule'] = $nested[0];
                }
                $mapped[] = $rule;

                continue;
            }

            $target_id = $this->mapColumn((int) $field_id, 'a condition column');
            if ($target_id === null) {
                continue;
            }
            if ($this->isOptionColumn((int) $field_id)) {
                $rule['values'] = array_values(array_filter(array_map(fn ($id) => $this->mapOption((int) $field_id, $target_id, $id), (array) ($rule['values'] ?? []))));
            }
            $rule['column_id'] = (string) $target_id;
            $mapped[] = $rule;
        }

        return $mapped;
    }

    /**
     * @param  array<int, array{type: string, params: array<string, mixed>}>  $actions
     * @return array<int, array{type: string, params: array<string, mixed>}>
     */
    private function mapActions(array $actions): array
    {
        return array_map(function (array $action) {
            $params = $action['params'];
            $source_target_column = $params['target_column_id'] ?? null;

            foreach (self::COLUMN_PARAMS as $key) {
                if (! empty($params[$key])) {
                    $params[$key] = $this->mapColumn((int) $params[$key], 'an action column');
                }
            }
            if (isset($params['match_column_id']) && $params['match_column_id'] !== 'name') {
                $params['match_column_id'] = (string) ($this->mapColumn((int) $params['match_column_id'], 'an action column') ?? 'name');
            }
            foreach ((array) ($params['field_mappings'] ?? []) as $index => $mapping) {
                $params['field_mappings'][$index]['column_id'] = $this->mapColumn((int) ($mapping['column_id'] ?? 0), 'a column to fill');
            }
            if (is_array($params['dynamic_value'] ?? null) && ! empty($params['dynamic_value']['column_id'])) {
                $params['dynamic_value']['column_id'] = $this->mapColumn((int) $params['dynamic_value']['column_id'], 'the column a value is read from');
            }
            if (isset($params['column_ids'])) {
                $params['column_ids'] = array_values(array_filter(array_map(fn ($id) => $this->mapColumn((int) $id, 'a column the digest shows'), (array) $params['column_ids'])));
            }
            if (isset($params['digest_rules'])) {
                $params['digest_rules'] = $this->mapRules((array) $params['digest_rules']);
            }

            if ($action['type'] === BoardAutomation::ACTION_CHANGE_VALUES && $this->isOptionColumn($source_target_column)) {
                $params['values'] = array_values(array_filter(array_map(fn ($id) => $this->mapOption((int) $source_target_column, $params['target_column_id'], $id), (array) ($params['values'] ?? []))));
            }
            if (! empty($params['destination_group_id'])) {
                $params['destination_group_id'] = $this->mapGroup((int) $params['destination_group_id']);
            }
            if (array_key_exists('value', $params) && $action['type'] !== BoardAutomation::ACTION_UPDATE_CONNECTED_ITEMS && $this->isOptionColumn($source_target_column)) {
                $params['value'] = is_array($params['value'])
                    ? array_values(array_filter(array_map(fn ($id) => $this->mapOption((int) $source_target_column, $params['target_column_id'], $id), $params['value'])))
                    : $this->mapOption((int) $source_target_column, $params['target_column_id'], $params['value']);
            }

            $is_cross_board = ! empty($params['target_board_id']) && (int) $params['target_board_id'] !== (int) $this->automation->board_id;
            if (! $is_cross_board) {
                foreach (['target_group_id', 'source_group_id'] as $key) {
                    if (! empty($params[$key])) {
                        $params[$key] = $this->mapGroup((int) $params[$key]);
                    }
                }
            }

            return ['type' => $action['type'], 'params' => array_filter($params, fn ($value) => $value !== null)];
        }, $actions);
    }

    private function mapColumn(mixed $column_id, string $what): ?int
    {
        $source = is_numeric($column_id) ? $this->source_columns->get((int) $column_id) : null;
        if (! $source) {
            return null;
        }

        $same_kind = $this->target_columns->filter(fn (BoardColumn $column) => $column->type === $source->type && $column->scope === $source->scope);
        $match = $same_kind->first(fn (BoardColumn $column) => mb_strtolower(trim($column->label)) === mb_strtolower(trim($source->label)))
            ?? ($same_kind->count() === 1 ? $same_kind->first() : null);

        if (! $match) {
            $this->unmapped[] = "\"{$source->label}\" ({$what})";
        }

        return $match?->id;
    }

    private function mapOption(mixed $source_column_id, mixed $target_column_id, mixed $option_id): ?string
    {
        $source = $this->source_columns->get((int) $source_column_id);
        $target = $target_column_id ? $this->target_columns->get((int) $target_column_id) : null;
        if (! $source || ! $target) {
            return null;
        }

        $label = collect($source->config['options'] ?? [])->firstWhere('id', (string) $option_id)['label'] ?? null;
        $match = $label === null ? null : collect($target->config['options'] ?? [])->first(fn (array $option) => mb_strtolower((string) ($option['label'] ?? '')) === mb_strtolower((string) $label));
        if (! $match) {
            $this->unmapped[] = 'the label "'.($label ?? $option_id)."\" of \"{$target->label}\"";
        }

        return $match ? (string) $match['id'] : null;
    }

    private function mapGroup(int $group_id): ?int
    {
        $source = $this->source_groups->get($group_id);
        $match = $source ? $this->target_groups->first(fn (BoardGroup $group) => mb_strtolower(trim($group->name)) === mb_strtolower(trim($source->name))) : null;
        if (! $match) {
            $this->unmapped[] = $source ? "the group \"{$source->name}\"" : 'a group';
        }

        return $match?->id;
    }

    private function isOptionColumn(mixed $column_id): bool
    {
        return is_numeric($column_id) && in_array($this->source_columns->get((int) $column_id)?->type, self::OPTION_TYPES, true);
    }
}
