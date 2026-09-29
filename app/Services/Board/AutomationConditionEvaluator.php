<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardItemValue;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides whether an item passes an automation's "and only if" rules. Rules on ordinary columns go
 * through the board filter engine ({@see BoardItemFilterEvaluator}); this class answers the fields
 * and operators only automations have, per column type:
 *
 * - Item details: {@see self::ACTOR_FIELD} (the person who set the automation off), the number
 *   of updates ({@see self::UPDATE_COUNT_FIELD}) and of subitems ({@see self::SUBITEM_COUNT_FIELD}).
 * - Subitems ({@see self::SUBITEMS_FIELD}): `all_match`, `any_match` or `none_match` of the nested
 *   `subitem_rule`, a rule on a subitem column.
 * - Formula: the result computed on the server ({@see BoardFormulaResolver}), compared as a number
 *   when both sides are numbers, otherwise as text.
 * - Mirror: the mirrored values as text. Connect boards: linked or not, or the linked item names.
 * - Dependency: `all_done` or `has_unfinished`, where done means the predecessor's status column
 *   `value` holds one of the labels in `values`.
 * - Time tracking: `is_running` or `is_not_running`.
 */
class AutomationConditionEvaluator
{
    public const ACTOR_FIELD = '__actor__';

    public const UPDATE_COUNT_FIELD = '__update_count__';

    public const SUBITEM_COUNT_FIELD = '__subitem_count__';

    public const SUBITEMS_FIELD = '__subitems__';

    /** Condition fields that are item details, not columns, the board filter ones included. */
    public const VIRTUAL_FIELDS = [
        'name', '__group__', '__created_by__', '__created_at__', '__updated_at__', '__starred__',
        self::ACTOR_FIELD, self::UPDATE_COUNT_FIELD, self::SUBITEM_COUNT_FIELD, self::SUBITEMS_FIELD,
    ];

    /** Operators only automation conditions use, on top of the board filter ones. */
    public const EXTRA_OPERATORS = ['is_running', 'is_not_running', 'all_done', 'has_unfinished', 'all_match', 'any_match', 'none_match'];

    private const NUMBER_OPERATORS = ['equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'between'];

    /** Set on a rule whose dynamic value named nothing on this run, see {@see self::resolveDynamicRules()}. */
    private const UNRESOLVED_FLAG = '__dynamic_unresolved';

    public function __construct(
        private readonly BoardFormulaResolver $formula_resolver,
        private readonly MirrorColumnResolver $mirror_resolver,
        private readonly BoardAutomationMessageRenderer $renderer,
        private readonly AutomationDynamicValueResolver $dynamic_values,
    ) {}

    /**
     * Whether `$item` passes the rules of `$filter_state` (`advanced_filter_rows`, `advanced_filter_groups`
     * and `advanced_filter_operator`), with `$actor` as whoever set the automation off.
     *
     * @param  array<string, mixed>  $filter_state
     * @param  Collection<string, BoardColumn>|null  $columns  every column of the tab keyed by id, read when null (a caller checking many items passes them once)
     */
    public function matches(BoardAutomation $automation, BoardItem $item, ?User $actor, array $filter_state, ?Collection $columns = null): bool
    {
        $scope = $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        $columns ??= self::tabColumns($automation);
        $scoped = $columns->filter(fn (BoardColumn $column) => $column->scope === $scope);

        $evaluator = new BoardItemFilterEvaluator($scoped, null, Carbon::today()->toDateString(), null, [], 'UTC', $this->extension($columns, $scoped, $actor));

        return $evaluator->matches($item->relationLoaded('values') ? $item : $item->load('values'), $this->resolveDynamicRules([
            'advanced_filter_rows' => [],
            'advanced_filter_groups' => [],
            'advanced_filter_operator' => 'and',
            ...$filter_state,
        ], $automation, $item, $actor, $columns));
    }

    /**
     * Reads every rule's dynamic value ("today + 3 days", "the person who made the change", another
     * column) for this item. A rule whose source names nothing is flagged, so it fails instead of
     * being skipped like an incomplete rule would be.
     *
     * @param  array<string, mixed>  $filter_state
     * @param  Collection<string, BoardColumn>  $columns
     * @return array<string, mixed>
     */
    private function resolveDynamicRules(array $filter_state, BoardAutomation $automation, BoardItem $item, ?User $actor, Collection $columns): array
    {
        $resolve = function (mixed $rule) use ($automation, $item, $actor, $columns): mixed {
            if (! is_array($rule) || ! AutomationDynamicValueResolver::isDynamic($rule['dynamic'] ?? null)) {
                return $rule;
            }

            return $this->dynamic_values->forRule($rule, $item, $actor, $automation, $columns) ?? [...$rule, self::UNRESOLVED_FLAG => true];
        };

        $filter_state['advanced_filter_rows'] = array_map($resolve, (array) $filter_state['advanced_filter_rows']);
        $filter_state['advanced_filter_groups'] = array_map(function (mixed $group) use ($resolve): mixed {
            if (is_array($group)) {
                $group['rules'] = array_map($resolve, (array) ($group['rules'] ?? []));
            }

            return $group;
        }, (array) $filter_state['advanced_filter_groups']);

        return $filter_state;
    }

    /**
     * Every column of the automation's tab, both scopes, keyed by id as a string.
     *
     * @return Collection<string, BoardColumn>
     */
    public static function tabColumns(BoardAutomation $automation): Collection
    {
        return BoardColumn::where('board_view_id', $automation->board_view_id)->get()->keyBy(fn (BoardColumn $column) => (string) $column->id);
    }

    /**
     * @param  Collection<string, BoardColumn>  $all_columns  every column of the tab, both scopes
     * @param  Collection<string, BoardColumn>  $columns  the columns of the item's own scope
     * @return Closure(BoardItem, array<string, mixed>): (array{result: bool|null}|null)
     */
    private function extension(Collection $all_columns, Collection $columns, ?User $actor): Closure
    {
        return function (BoardItem $item, array $rule) use ($all_columns, $columns, $actor): ?array {
            if (! empty($rule[self::UNRESOLVED_FLAG])) {
                return ['result' => false];
            }

            $field_id = (string) ($rule['column_id'] ?? '');
            $operator = (string) ($rule['condition'] ?? '');
            $value = (string) ($rule['value'] ?? '');
            $values = array_map('strval', array_values(array_filter((array) ($rule['values'] ?? []), 'is_scalar')));
            if ($field_id === '' || $operator === '') {
                return null;
            }

            $result = match ($field_id) {
                self::ACTOR_FIELD => ['result' => $this->evaluateActor($actor, $operator, $values)],
                self::UPDATE_COUNT_FIELD => ['result' => BoardItemFilterEvaluator::evaluateNumberRule($this->updateCount($item), $operator, $value, $values)],
                self::SUBITEM_COUNT_FIELD => ['result' => BoardItemFilterEvaluator::evaluateNumberRule((float) $this->subitems($item)->count(), $operator, $value, $values)],
                self::SUBITEMS_FIELD => ['result' => $this->evaluateSubitems($item, $all_columns, $actor, $operator, (array) ($rule['subitem_rule'] ?? []))],
                default => null,
            };
            if ($result !== null) {
                return $result;
            }

            $column = $columns->get($field_id);
            if (! $column) {
                return null;
            }

            return match ($column->type) {
                BoardColumn::TYPE_FORMULA => ['result' => $this->evaluateFormula($column, $item, $operator, $value, $values)],
                BoardColumn::TYPE_MIRROR => ['result' => BoardItemFilterEvaluator::evaluateTextOperator($this->mirrorText($column, $item, $all_columns), $operator, $value)],
                BoardColumn::TYPE_CONNECT_BOARD => ['result' => $this->evaluateLinked($column, $item, $operator, $value)],
                BoardColumn::TYPE_DEPENDENCY => ['result' => $this->evaluateDependency($column, $item, $operator, $value, $values)],
                BoardColumn::TYPE_TIME_TRACKING => in_array($operator, ['is_running', 'is_not_running'], true)
                    ? ['result' => $this->isTimerRunning($column, $item) === ($operator === 'is_running')]
                    : null,
                default => null,
            };
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    private function evaluateActor(?User $actor, string $operator, array $values): ?bool
    {
        $actor_id = $actor ? (string) $actor->id : null;

        return match ($operator) {
            'is_empty' => $actor_id === null,
            'is_not_empty' => $actor_id !== null,
            'is', 'equals' => $actor_id !== null && in_array($actor_id, $values, true),
            'is_not', 'not_equals' => $actor_id === null || ! in_array($actor_id, $values, true),
            default => null,
        };
    }

    private function updateCount(BoardItem $item): float
    {
        return (float) BoardItemComment::where('item_id', $item->id)->whereNull('parent_id')->count();
    }

    /**
     * @return Collection<int, BoardItem>
     */
    private function subitems(BoardItem $item): Collection
    {
        return BoardItem::where('parent_id', $item->id)->where('is_archived', false)->with('values')->get();
    }

    /**
     * "all subitems", "any subitem" or "no subitem" pass the nested rule. An item without subitems
     * passes neither "all" nor "any".
     *
     * @param  Collection<string, BoardColumn>  $all_columns
     * @param  array<string, mixed>  $subitem_rule
     */
    private function evaluateSubitems(BoardItem $item, Collection $all_columns, ?User $actor, string $operator, array $subitem_rule): ?bool
    {
        if (! in_array($operator, ['all_match', 'any_match', 'none_match'], true) || ($subitem_rule['column_id'] ?? '') === '') {
            return null;
        }

        $subitem_columns = $all_columns->filter(fn (BoardColumn $column) => $column->scope === BoardColumn::SCOPE_SUBITEM);
        $evaluator = new BoardItemFilterEvaluator($subitem_columns, null, Carbon::today()->toDateString(), null, [], 'UTC', $this->extension($all_columns, $subitem_columns, $actor));
        $state = ['advanced_filter_rows' => [$subitem_rule], 'advanced_filter_groups' => [], 'advanced_filter_operator' => 'and'];

        $subitems = $this->subitems($item);
        if ($subitems->isEmpty()) {
            return $operator === 'none_match';
        }

        $passing = $subitems->filter(fn (BoardItem $subitem) => $evaluator->matches($subitem, $state))->count();

        return match ($operator) {
            'all_match' => $passing === $subitems->count(),
            'any_match' => $passing > 0,
            default => $passing === 0,
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    private function evaluateFormula(BoardColumn $column, BoardItem $item, string $operator, string $value, array $values): ?bool
    {
        $outcome = $this->formula_resolver->outcome($column, $item);
        $text = $outcome !== null && $outcome['ok'] ? $outcome['text'] : '';
        $result = $outcome !== null && $outcome['ok'] ? $outcome['value'] : null;
        $number = is_int($result) || is_float($result) ? (float) $result : (is_numeric($text) ? (float) $text : null);

        if (in_array($operator, self::NUMBER_OPERATORS, true)) {
            return BoardItemFilterEvaluator::evaluateNumberRule($number, $operator, $value, $values);
        }
        if (in_array($operator, ['is', 'is_not'], true) && $number !== null && is_numeric($value)) {
            $is_equal = abs($number - (float) $value) < 0.000001;

            return $operator === 'is' ? $is_equal : ! $is_equal;
        }

        return BoardItemFilterEvaluator::evaluateTextOperator($text, $operator, $value);
    }

    /**
     * The mirrored values of the linked items, as their own columns show them.
     *
     * @param  Collection<string, BoardColumn>  $all_columns
     */
    private function mirrorText(BoardColumn $column, BoardItem $item, Collection $all_columns): string
    {
        $copy = (clone $item)->setRelations([]);
        $this->mirror_resolver->attach(collect([$copy]), $all_columns->values());
        $mirrored = ($copy->getAttribute('mirror_values') ?? [])[(string) $column->id] ?? null;
        if ($mirrored === null) {
            return '';
        }

        // One linked item mirrors as its value, several as a list of values, see `MirrorColumnResolver`.
        $mirrored_column = BoardColumn::find((int) ($column->config['mirrored_column_id'] ?? 0));
        $source_column = $all_columns->get((string) ($column->config['source_column_id'] ?? ''));
        $linked_count = $source_column && $mirrored_column
            ? BoardItemValue::whereIn('item_id', $this->linkedIds($source_column, $item))->where('column_id', $mirrored_column->id)->whereNotNull('value')->count()
            : 1;
        $entries = $linked_count > 1 && is_array($mirrored) ? $mirrored : [$mirrored];

        return implode(', ', array_filter(array_map(
            fn ($entry) => $mirrored_column ? $this->renderer->displayValue($mirrored_column, $entry) : (is_scalar($entry) ? (string) $entry : ''),
            $entries
        ), fn (string $text) => $text !== ''));
    }

    private function evaluateLinked(BoardColumn $column, BoardItem $item, string $operator, string $value): ?bool
    {
        $linked_ids = $this->linkedIds($column, $item);

        if ($operator === 'is_empty' || $operator === 'is_not_empty') {
            return ($linked_ids === []) === ($operator === 'is_empty');
        }

        $names = $linked_ids === [] ? '' : BoardItem::whereIn('id', $linked_ids)->pluck('name')->implode(' | ');

        return BoardItemFilterEvaluator::evaluateTextOperator($names, $operator, $value);
    }

    /**
     * "All dependencies are done" or "blocked by an unfinished item". Done means the predecessor's
     * status column `$status_column_id` holds one of `$done_values`.
     *
     * @param  array<int, string>  $done_values
     */
    private function evaluateDependency(BoardColumn $column, BoardItem $item, string $operator, string $status_column_id, array $done_values): ?bool
    {
        $predecessor_ids = $this->linkedIds($column, $item);

        if ($operator === 'is_empty' || $operator === 'is_not_empty') {
            return ($predecessor_ids === []) === ($operator === 'is_empty');
        }
        if (! in_array($operator, ['all_done', 'has_unfinished'], true) || $status_column_id === '' || $done_values === []) {
            return null;
        }

        $statuses = BoardItemValue::whereIn('item_id', $predecessor_ids)->where('column_id', (int) $status_column_id)->pluck('value', 'item_id');
        $live_ids = BoardItem::whereIn('id', $predecessor_ids)->where('is_archived', false)->pluck('id');
        $unfinished = $live_ids->filter(fn (int $id) => ! in_array((string) ($statuses[$id] ?? ''), $done_values, true))->count();

        return $operator === 'all_done' ? $unfinished === 0 : $unfinished > 0;
    }

    /**
     * @return array<int, int>
     */
    private function linkedIds(BoardColumn $column, BoardItem $item): array
    {
        $value = $item->values->firstWhere('column_id', $column->id)?->value;

        return array_values(array_map('intval', array_filter((array) ($value ?? []), fn ($id) => is_numeric($id))));
    }

    private function isTimerRunning(BoardColumn $column, BoardItem $item): bool
    {
        $value = $item->values->firstWhere('column_id', $column->id)?->value;

        return is_array($value) && ! empty($value['running_since']);
    }
}
