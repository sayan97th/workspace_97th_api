<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Server-side twin of the frontend's `filterEngine.ts` and `deriveBoardRows.ts`:
 * decides whether one root item matches a board view's filter state (Person
 * with teams and chosen People columns, Quick filters picks and exclusions,
 * and Advanced filters with And/Or groups and paused rules). Every column type
 * maps onto the same filter family as on the client, so a filter narrows rows
 * identically in both places.
 *
 * With "Filter subitems" on, rules and facets on subitem columns are checked
 * against each subitem: the item matches when one of its subitems passes
 * together with every item rule (an item without subitems reads them as empty).
 *
 * Conditions the server cannot evaluate (Formula columns, whose values are
 * computed in the browser, and rules saved as free text before typed
 * conditions existed) pass every row. The client filters again on top of the
 * server's result, so the server only ever needs to return a superset.
 */
class BoardItemFilterEvaluator
{
    public const GROUP_FIELD_ID = '__group__';

    public const NAME_FIELD_ID = 'name';

    public const ME_VALUE = '__me__';

    public const BLANK_OPTION_ID = '__blank__';

    public const CREATED_BY_FIELD_ID = '__created_by__';

    public const CREATED_AT_FIELD_ID = '__created_at__';

    public const UPDATED_AT_FIELD_ID = '__updated_at__';

    private const SCOPE_ITEM = 'item';

    private const SCOPE_SUBITEM = 'subitem';

    private const KIND_OPTION = 'option';

    private const KIND_PEOPLE = 'people';

    private const KIND_GROUP = 'group';

    private const KIND_TEXT = 'text';

    private const KIND_NUMBER = 'number';

    private const KIND_DATE = 'date';

    private const KIND_CHECKBOX = 'checkbox';

    private const MIN_DATE = '0000-01-01';

    private const MAX_DATE = '9999-12-31';

    /** @var array<string, string> column type => filter kind */
    private const KIND_BY_COLUMN_TYPE = [
        BoardColumn::TYPE_STATUS => self::KIND_OPTION,
        BoardColumn::TYPE_LABEL => self::KIND_OPTION,
        BoardColumn::TYPE_DROPDOWN => self::KIND_OPTION,
        BoardColumn::TYPE_TAGS => self::KIND_OPTION,
        BoardColumn::TYPE_PEOPLE => self::KIND_PEOPLE,
        BoardColumn::TYPE_VOTE => self::KIND_PEOPLE,
        BoardColumn::TYPE_DATE => self::KIND_DATE,
        BoardColumn::TYPE_TIMELINE => self::KIND_DATE,
        BoardColumn::TYPE_NUMBER => self::KIND_NUMBER,
        BoardColumn::TYPE_RATING => self::KIND_NUMBER,
        BoardColumn::TYPE_PROGRESS => self::KIND_NUMBER,
        BoardColumn::TYPE_AUTO_NUMBER => self::KIND_NUMBER,
        BoardColumn::TYPE_TIME_TRACKING => self::KIND_NUMBER,
        BoardColumn::TYPE_CHECKBOX => self::KIND_CHECKBOX,
        BoardColumn::TYPE_TEXT => self::KIND_TEXT,
        BoardColumn::TYPE_LONG_TEXT => self::KIND_TEXT,
        BoardColumn::TYPE_EMAIL => self::KIND_TEXT,
        BoardColumn::TYPE_PHONE => self::KIND_TEXT,
        BoardColumn::TYPE_LINK => self::KIND_TEXT,
        BoardColumn::TYPE_FILES => self::KIND_TEXT,
        BoardColumn::TYPE_MIRROR => self::KIND_TEXT,
        BoardColumn::TYPE_CHECKLIST => self::KIND_TEXT,
    ];

    /**
     * @param  Collection<string, BoardColumn>  $columns  the view's item-scoped columns, keyed by id as a string
     * @param  Collection<string, BoardColumn>|null  $subitem_columns  the view's subitem-scoped columns, keyed by id as a string
     * @param  array<string, array<int, int>>  $team_member_ids  account team id => ids of its members, for the Person filter's teams
     * @param  string  $timezone  the viewer's time zone, which turns Creation date and Last updated timestamps into days
     */
    public function __construct(
        private readonly Collection $columns,
        private readonly ?int $current_user_id,
        private readonly string $today,
        private readonly ?Collection $subitem_columns = null,
        private readonly array $team_member_ids = [],
        private readonly string $timezone = 'UTC',
    ) {}

    /**
     * Whether a filter state narrows rows at all (a complete Advanced rule, a
     * Quick filters pick or a Person pick).
     *
     * @param  array<string, mixed>  $filter_state
     */
    public static function hasActiveFilters(array $filter_state): bool
    {
        if (! empty($filter_state['selected_person_ids']) || ! empty($filter_state['selected_team_ids'])) {
            return true;
        }

        foreach (['quick_filter_selections', 'quick_filter_exclusions'] as $key) {
            foreach ((array) ($filter_state[$key] ?? []) as $option_ids) {
                if (! empty($option_ids)) {
                    return true;
                }
            }
        }

        foreach ((array) ($filter_state['advanced_filter_rows'] ?? []) as $rule) {
            if (is_array($rule) && self::isRuleApplicable($rule)) {
                return true;
            }
        }

        foreach ((array) ($filter_state['advanced_filter_groups'] ?? []) as $group) {
            foreach ((array) ($group['rules'] ?? []) as $rule) {
                if (is_array($rule) && self::isRuleApplicable($rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A complete rule that is not paused. A paused rule stays in the list but narrows nothing.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function isRuleApplicable(array $rule): bool
    {
        return empty($rule['is_disabled']) && self::isRuleComplete($rule);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function isRuleComplete(array $rule): bool
    {
        $column_id = $rule['column_id'] ?? null;
        $condition = $rule['condition'] ?? null;
        if (! is_string($column_id) || $column_id === '' || ! is_string($condition) || $condition === '') {
            return false;
        }

        if (self::isValuelessOperator($condition)) {
            return true;
        }

        $values = array_values(array_filter((array) ($rule['values'] ?? []), fn ($value) => is_scalar($value)));
        if ($condition === 'between') {
            return count($values) === 2 && (string) $values[0] !== '' && (string) $values[1] !== '';
        }

        return count($values) > 0 || trim((string) ($rule['value'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $filter_state
     */
    public function matches(BoardItem $item, array $filter_state): bool
    {
        $include_subitems = (bool) ($filter_state['include_subitems'] ?? false);
        $selections = (array) ($filter_state['quick_filter_selections'] ?? []);
        $exclusions = (array) ($filter_state['quick_filter_exclusions'] ?? []);

        $passes_item_checks = $this->matchesPerson($item, $filter_state)
            && $this->matchesQuickFilters($item, $selections, $exclusions, self::SCOPE_ITEM, $include_subitems);
        if (! $passes_item_checks) {
            return false;
        }

        if (! $include_subitems || ! $this->hasSubitemFilters($filter_state)) {
            return $this->matchesAdvancedFilters($item, null, $filter_state, $include_subitems);
        }

        $sub_items = $this->subItems($item);
        if ($sub_items->isEmpty()) {
            return $this->matchesQuickFilters(null, $selections, $exclusions, self::SCOPE_SUBITEM, true)
                && $this->matchesAdvancedFilters($item, null, $filter_state, true);
        }

        return $sub_items->contains(
            fn (BoardItem $sub_item) => $this->matchesQuickFilters($sub_item, $selections, $exclusions, self::SCOPE_SUBITEM, true)
                && $this->matchesAdvancedFilters($item, $sub_item, $filter_state, true)
        );
    }

    /**
     * Whether a subitem rule or a subitem facet pick applies.
     *
     * @param  array<string, mixed>  $filter_state
     */
    private function hasSubitemFilters(array $filter_state): bool
    {
        foreach (['quick_filter_selections', 'quick_filter_exclusions'] as $key) {
            foreach ((array) ($filter_state[$key] ?? []) as $field_id => $option_ids) {
                if (! empty($option_ids) && ($this->resolveField((string) $field_id, true)['scope'] ?? null) === self::SCOPE_SUBITEM) {
                    return true;
                }
            }
        }

        $rules = (array) ($filter_state['advanced_filter_rows'] ?? []);
        foreach ((array) ($filter_state['advanced_filter_groups'] ?? []) as $group) {
            $rules = [...$rules, ...(array) ($group['rules'] ?? [])];
        }
        foreach ($rules as $rule) {
            if (is_array($rule) && self::isRuleApplicable($rule)
                && ($this->resolveField((string) $rule['column_id'], true)['scope'] ?? null) === self::SCOPE_SUBITEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * An item's live (not archived) subitems, with their values.
     *
     * @return Collection<int, BoardItem>
     */
    private function subItems(BoardItem $item): Collection
    {
        if ($item->relationLoaded('children')) {
            return $item->children->where('is_archived', false)->values();
        }

        return $item->children()->where('is_archived', false)->with('values')->get();
    }

    /**
     * Same as the client's Person filter: the picked people plus the members of
     * the picked teams, looked for in the chosen People columns (every People
     * column when none are chosen).
     *
     * @param  array<string, mixed>  $filter_state
     */
    private function matchesPerson(BoardItem $item, array $filter_state): bool
    {
        $wanted = array_map('strval', (array) ($filter_state['selected_person_ids'] ?? []));
        $team_ids = array_map('strval', (array) ($filter_state['selected_team_ids'] ?? []));
        if ($wanted === [] && $team_ids === []) {
            return true;
        }
        foreach ($team_ids as $team_id) {
            $wanted = [...$wanted, ...array_map('strval', $this->team_member_ids[$team_id] ?? [])];
        }

        $column_ids = array_map('strval', (array) ($filter_state['person_column_ids'] ?? []));
        foreach ($this->columns as $column_id => $column) {
            if ($column->type !== BoardColumn::TYPE_PEOPLE) {
                continue;
            }
            if ($column_ids !== [] && ! in_array((string) $column_id, $column_ids, true)) {
                continue;
            }
            if (array_intersect($this->optionIds($item, $column), $wanted) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The facets of one scope: a row must hold one of each facet's picks (when
     * it has any) and none of its exclusions. `$row` is null for a parent
     * without subitems, which reads every subitem facet as blank.
     *
     * @param  array<string, mixed>  $selections  facet (field) id => picked option ids
     * @param  array<string, mixed>  $exclusions  facet (field) id => excluded option ids
     */
    private function matchesQuickFilters(?BoardItem $row, array $selections, array $exclusions, string $scope, bool $include_subitems): bool
    {
        $field_ids = array_unique([...array_keys($selections), ...array_keys($exclusions)]);

        foreach ($field_ids as $field_id) {
            $picked = array_map('strval', (array) ($selections[$field_id] ?? []));
            $excluded = array_map('strval', (array) ($exclusions[$field_id] ?? []));
            if ($picked === [] && $excluded === []) {
                continue;
            }

            $field = $this->resolveField((string) $field_id, $include_subitems);
            if ($field === null || $field['scope'] !== $scope) {
                continue;
            }

            $row_ids = $row === null ? [self::BLANK_OPTION_ID] : $this->quickOptionIds($row, $field);
            if ($picked !== [] && array_intersect($row_ids, $picked) === []) {
                return false;
            }
            if (array_intersect($row_ids, $excluded) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Top-level rules and groups combined with the top-level And/Or, each
     * group's rules with its own. Incomplete rules and empty groups are skipped.
     *
     * @param  array<string, mixed>  $filter_state
     */
    private function matchesAdvancedFilters(BoardItem $item, ?BoardItem $sub_item, array $filter_state, bool $include_subitems): bool
    {
        $results = [];

        foreach ((array) ($filter_state['advanced_filter_rows'] ?? []) as $rule) {
            $result = is_array($rule) ? $this->evaluateRule($item, $sub_item, $rule, $include_subitems) : null;
            if ($result !== null) {
                $results[] = $result;
            }
        }

        foreach ((array) ($filter_state['advanced_filter_groups'] ?? []) as $group) {
            $group_results = [];
            foreach ((array) ($group['rules'] ?? []) as $rule) {
                $result = is_array($rule) ? $this->evaluateRule($item, $sub_item, $rule, $include_subitems) : null;
                if ($result !== null) {
                    $group_results[] = $result;
                }
            }
            if ($group_results !== []) {
                $results[] = $this->combine($group_results, (string) ($group['join_operator'] ?? 'and'));
            }
        }

        if ($results === []) {
            return true;
        }

        return $this->combine($results, (string) ($filter_state['advanced_filter_operator'] ?? 'and'));
    }

    /**
     * @param  array<int, bool>  $results
     */
    private function combine(array $results, string $operator): bool
    {
        return $operator === 'or' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * Evaluates one rule, or returns null when it should be ignored (incomplete,
     * paused, unknown field, or a condition the server cannot evaluate). A
     * subitem rule reads `$sub_item`, or an empty cell when it is null.
     *
     * @param  array<string, mixed>  $rule
     */
    private function evaluateRule(BoardItem $item, ?BoardItem $sub_item, array $rule, bool $include_subitems): ?bool
    {
        if (! self::isRuleApplicable($rule)) {
            return null;
        }

        $field = $this->resolveField((string) $rule['column_id'], $include_subitems);
        if ($field === null) {
            return null;
        }
        $row = $field['scope'] === self::SCOPE_SUBITEM ? $sub_item : $item;

        $operator = (string) $rule['condition'];
        $value = (string) ($rule['value'] ?? '');
        $values = array_map('strval', array_values(array_filter((array) ($rule['values'] ?? []), fn ($entry) => is_scalar($entry))));

        return match ($field['kind']) {
            self::KIND_OPTION, self::KIND_PEOPLE, self::KIND_GROUP => $this->evaluateOptionRule($row, $field, $operator, $values),
            self::KIND_NUMBER => $operator === 'contains'
                ? $this->evaluateTextOperator($this->text($row, $field), $operator, $value)
                : $this->evaluateNumberRule($this->number($row, $field), $operator, $value, $values),
            self::KIND_DATE => $this->evaluateDateRule($this->dateRange($row, $field), $operator, $value, $values),
            self::KIND_CHECKBOX => $this->evaluateCheckboxRule($this->checked($row, $field), $operator),
            default => $this->evaluateTextOperator($this->text($row, $field), $operator, $value),
        };
    }

    /**
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     * @param  array<int, string>  $values
     */
    private function evaluateOptionRule(?BoardItem $item, array $field, string $operator, array $values): ?bool
    {
        $row_ids = $this->fieldOptionIds($item, $field);

        if ($operator === 'is_empty') {
            return $row_ids === [];
        }
        if ($operator === 'is_not_empty') {
            return $row_ids !== [];
        }

        // A rule saved before typed conditions existed matches option labels as
        // free text, which only the client can resolve.
        if ($values === []) {
            return null;
        }

        $wanted = [];
        foreach ($values as $entry) {
            if ($entry === self::ME_VALUE) {
                if ($this->current_user_id !== null) {
                    $wanted[] = (string) $this->current_user_id;
                }
            } else {
                $wanted[] = $entry;
            }
        }
        $has_match = array_intersect($row_ids, $wanted) !== [];

        return match ($operator) {
            'is', 'equals' => $has_match,
            'is_not', 'not_equals' => ! $has_match,
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    private function evaluateNumberRule(?float $number, string $operator, string $value, array $values): ?bool
    {
        if ($operator === 'is_empty') {
            return $number === null;
        }
        if ($operator === 'is_not_empty') {
            return $number !== null;
        }
        if ($operator === 'between') {
            if ($number === null || ! is_numeric($values[0] ?? null) || ! is_numeric($values[1] ?? null)) {
                return false;
            }
            $min = min((float) $values[0], (float) $values[1]);
            $max = max((float) $values[0], (float) $values[1]);

            return $number >= $min && $number <= $max;
        }
        if (! is_numeric($value)) {
            return null;
        }

        $target = (float) $value;
        if ($operator === 'not_equals') {
            return $number !== $target;
        }
        if ($number === null) {
            return false;
        }

        return match ($operator) {
            'equals', 'is' => $number === $target,
            'greater_than' => $number > $target,
            'greater_or_equal' => $number >= $target,
            'less_than' => $number < $target,
            'less_or_equal' => $number <= $target,
            default => null,
        };
    }

    /**
     * @param  array{start: string, end: string}|null  $range
     * @param  array<int, string>  $values
     */
    private function evaluateDateRule(?array $range, string $operator, string $value, array $values): ?bool
    {
        if ($operator === 'is_empty') {
            return $range === null;
        }
        if ($operator === 'is_not_empty') {
            return $range !== null;
        }

        if ($operator === 'between') {
            $from = $this->resolveDateValue($values[0] ?? '');
            $to = $this->resolveDateValue($values[1] ?? '');
            $target = $from !== null && $to !== null ? ['start' => $from['start'], 'end' => $to['end']] : null;
        } else {
            $target = $this->resolveDateValue($value);
        }
        if ($target === null) {
            return null;
        }

        if ($operator === 'is_not') {
            return $range === null || ! $this->rangesOverlap($range, $target);
        }
        if ($range === null) {
            return false;
        }

        return match ($operator) {
            'is', 'equals', 'between' => $this->rangesOverlap($range, $target),
            'before' => $range['end'] < $target['start'],
            'after' => $range['start'] > $target['end'],
            'on_or_before' => $range['end'] <= $target['end'],
            'on_or_after' => $range['start'] >= $target['start'],
            default => null,
        };
    }

    private function evaluateCheckboxRule(bool $is_checked, string $operator): ?bool
    {
        return match ($operator) {
            'is_checked', 'is_not_empty' => $is_checked,
            'is_unchecked', 'is_empty' => ! $is_checked,
            default => null,
        };
    }

    private function evaluateTextOperator(string $text, string $operator, string $value): ?bool
    {
        $haystack = mb_strtolower(trim($text));
        $needle = mb_strtolower(trim($value));

        return match ($operator) {
            'is', 'equals' => $haystack === $needle,
            'is_not', 'not_equals' => $haystack !== $needle,
            'contains' => str_contains($haystack, $needle),
            'not_contains' => ! str_contains($haystack, $needle),
            'starts_with' => str_starts_with($haystack, $needle),
            'ends_with' => str_ends_with($haystack, $needle),
            'is_empty' => $haystack === '',
            'is_not_empty' => $haystack !== '',
            default => null,
        };
    }

    /**
     * Quick filters option ids a row falls under, mirroring the client's
     * `buildQuickFilterFacets`.
     *
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     * @return array<int, string>
     */
    private function quickOptionIds(BoardItem $item, array $field): array
    {
        switch ($field['kind']) {
            case self::KIND_OPTION:
            case self::KIND_PEOPLE:
            case self::KIND_GROUP:
                $ids = $this->fieldOptionIds($item, $field);

                return $ids === [] ? [self::BLANK_OPTION_ID] : $ids;
            case self::KIND_CHECKBOX:
                return [$this->checked($item, $field) ? 'checked' : 'unchecked'];
            case self::KIND_DATE:
                return $this->dateBucketIds($this->dateRange($item, $field));
            case self::KIND_NUMBER:
                $number = $this->number($item, $field);
                if ($number === null) {
                    return [self::BLANK_OPTION_ID];
                }

                return $field['column']?->type === BoardColumn::TYPE_RATING ? [(string) (int) round($number)] : ['has_value'];
            default:
                return [trim($this->text($item, $field)) === '' ? self::BLANK_OPTION_ID : 'has_value'];
        }
    }

    /**
     * @param  array{start: string, end: string}|null  $range
     * @return array<int, string>
     */
    private function dateBucketIds(?array $range): array
    {
        if ($range === null) {
            return [self::BLANK_OPTION_ID];
        }

        $ids = [];
        if ($range['end'] < $this->today) {
            $ids[] = 'overdue';
        }
        foreach (['today', 'tomorrow', 'this_week', 'next_week', 'this_month'] as $bucket) {
            $target = $this->resolveDateValue($bucket);
            if ($target !== null && $this->rangesOverlap($range, $target)) {
                $ids[] = $bucket;
            }
        }
        if ($range['start'] > $this->today) {
            $ids[] = 'upcoming';
        }

        return $ids;
    }

    /**
     * Resolves a date rule value (exact `YYYY-MM-DD` or a relative preset) to
     * the inclusive day range it covers. Weeks start on Monday, like the client.
     *
     * @return array{start: string, end: string}|null
     */
    private function resolveDateValue(string $value): ?array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return ['start' => $value, 'end' => $value];
        }

        $now = CarbonImmutable::createFromFormat('Y-m-d', $this->today)->startOfDay();
        $day = fn (CarbonImmutable $date) => $date->format('Y-m-d');
        $week = fn (CarbonImmutable $date) => [
            'start' => $day($date->startOfWeek(CarbonImmutable::MONDAY)),
            'end' => $day($date->endOfWeek(CarbonImmutable::SUNDAY)),
        ];
        $month = fn (CarbonImmutable $date) => ['start' => $day($date->startOfMonth()), 'end' => $day($date->endOfMonth())];

        return match ($value) {
            'today' => ['start' => $this->today, 'end' => $this->today],
            'tomorrow' => ['start' => $day($now->addDay()), 'end' => $day($now->addDay())],
            'yesterday' => ['start' => $day($now->subDay()), 'end' => $day($now->subDay())],
            'this_week' => $week($now),
            'last_week' => $week($now->subWeek()),
            'next_week' => $week($now->addWeek()),
            'this_month' => $month($now),
            'last_month' => $month($now->startOfMonth()->subMonth()),
            'next_month' => $month($now->startOfMonth()->addMonth()),
            'past' => ['start' => self::MIN_DATE, 'end' => $day($now->subDay())],
            'future' => ['start' => $day($now->addDay()), 'end' => self::MAX_DATE],
            default => null,
        };
    }

    /**
     * @param  array{start: string, end: string}  $a
     * @param  array{start: string, end: string}  $b
     */
    private function rangesOverlap(array $a, array $b): bool
    {
        return $a['start'] <= $b['end'] && $b['start'] <= $a['end'];
    }

    /**
     * The field a rule or facet id points at. Subitem columns only resolve while
     * "Filter subitems" is on, like the client only offers them then.
     *
     * @return array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}|null
     */
    private function resolveField(string $field_id, bool $include_subitems = false): ?array
    {
        $virtual_kind = match ($field_id) {
            self::NAME_FIELD_ID => self::KIND_TEXT,
            self::GROUP_FIELD_ID => self::KIND_GROUP,
            self::CREATED_BY_FIELD_ID => self::KIND_PEOPLE,
            self::CREATED_AT_FIELD_ID, self::UPDATED_AT_FIELD_ID => self::KIND_DATE,
            default => null,
        };
        if ($virtual_kind !== null) {
            return ['kind' => $virtual_kind, 'column' => null, 'scope' => self::SCOPE_ITEM, 'meta' => $field_id];
        }

        $column = $this->columns->get($field_id);
        $scope = self::SCOPE_ITEM;
        if ($column === null && $include_subitems) {
            $column = $this->subitem_columns?->get($field_id);
            $scope = self::SCOPE_SUBITEM;
        }
        $kind = $column ? (self::KIND_BY_COLUMN_TYPE[$column->type] ?? null) : null;

        return $kind === null ? null : ['kind' => $kind, 'column' => $column, 'scope' => $scope, 'meta' => null];
    }

    /**
     * Option ids a row holds for an option, people or group field, including
     * the item detail "Created by" field.
     *
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     * @return array<int, string>
     */
    private function fieldOptionIds(?BoardItem $item, array $field): array
    {
        if ($item === null) {
            return [];
        }
        if ($field['meta'] === self::CREATED_BY_FIELD_ID) {
            return $item->created_by_id !== null ? [(string) $item->created_by_id] : [];
        }

        return $this->optionIds($item, $field['column']);
    }

    /**
     * A timestamp as the day the viewer sees it, in their own time zone.
     */
    private function localDay(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->copy()->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * When the item or any of its values last changed, the "Last updated" field.
     * Reads the loaded `values` relation, the timestamps it carries are enough.
     */
    public static function lastUpdatedAt(BoardItem $item): ?CarbonInterface
    {
        $latest = $item->updated_at;
        if ($item->relationLoaded('values')) {
            foreach ($item->values as $value) {
                if ($value->updated_at !== null && ($latest === null || $value->updated_at->greaterThan($latest))) {
                    $latest = $value->updated_at;
                }
            }
        }

        return $latest;
    }

    private function rawValue(?BoardItem $item, BoardColumn $column): mixed
    {
        return $item?->values->firstWhere('column_id', $column->id)?->value;
    }

    /**
     * @return array<int, string>
     */
    private function optionIds(BoardItem $item, ?BoardColumn $column): array
    {
        if ($column === null) {
            return [(string) $item->group_id];
        }

        $value = $this->rawValue($item, $column);
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL], true)) {
            return is_scalar($value) ? [(string) $value] : [];
        }

        return array_values(array_map('strval', array_filter((array) $value, fn ($entry) => is_scalar($entry))));
    }

    /**
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     */
    private function number(?BoardItem $item, array $field): ?float
    {
        $column = $field['column'];
        if ($column === null) {
            return null;
        }

        $value = $this->rawValue($item, $column);
        if ($column->type === BoardColumn::TYPE_TIME_TRACKING) {
            $seconds = is_array($value) ? ($value['seconds'] ?? null) : null;

            // Hours, the unit the client filters time tracking in.
            return is_numeric($seconds) && $seconds > 0 ? round($seconds / 3600, 2) : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     * @return array{start: string, end: string}|null
     */
    private function dateRange(?BoardItem $item, array $field): ?array
    {
        if ($item !== null && ($field['meta'] === self::CREATED_AT_FIELD_ID || $field['meta'] === self::UPDATED_AT_FIELD_ID)) {
            $day = $this->localDay($field['meta'] === self::CREATED_AT_FIELD_ID ? $item->created_at : self::lastUpdatedAt($item));

            return $day === null ? null : ['start' => $day, 'end' => $day];
        }

        $column = $field['column'];
        if ($column === null) {
            return null;
        }

        $value = $this->rawValue($item, $column);
        $toDay = fn ($entry) => is_string($entry) && preg_match('/^\d{4}-\d{2}-\d{2}/', $entry) === 1 ? substr($entry, 0, 10) : null;

        if ($column->type === BoardColumn::TYPE_TIMELINE) {
            if (! is_array($value)) {
                return null;
            }
            $start = $toDay($value['start'] ?? null) ?? $toDay($value['end'] ?? null);
            $end = $toDay($value['end'] ?? null) ?? $start;

            return $start !== null && $end !== null ? ['start' => $start, 'end' => $end] : null;
        }

        $day = $toDay($value);

        return $day === null ? null : ['start' => $day, 'end' => $day];
    }

    /**
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     */
    private function checked(?BoardItem $item, array $field): bool
    {
        $value = $field['column'] ? $this->rawValue($item, $field['column']) : null;

        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Display text for text conditions, matching the client's `getColumnText`.
     *
     * @param  array{kind: string, column: BoardColumn|null, scope: string, meta: string|null}  $field
     */
    private function text(?BoardItem $item, array $field): string
    {
        if ($item === null) {
            return '';
        }
        $column = $field['column'];
        if ($column === null) {
            return $field['kind'] === self::KIND_TEXT ? (string) $item->name : '';
        }

        $value = $this->rawValue($item, $column);
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            BoardColumn::TYPE_LINK => is_array($value) ? trim(($value['text'] ?? '').' '.($value['url'] ?? '')) : (string) $value,
            BoardColumn::TYPE_FILES => implode(' ', array_map(fn ($file) => is_array($file) ? (string) ($file['file_name'] ?? '') : '', (array) $value)),
            BoardColumn::TYPE_CHECKLIST => trim(implode(' ', array_map(fn ($entry) => is_array($entry) ? (string) ($entry['text'] ?? '') : '', (array) $value))),
            default => is_array($value)
                ? implode(' ', array_map(fn ($entry) => is_scalar($entry) ? (string) $entry : '', $value))
                : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
        };
    }

    private static function isValuelessOperator(string $operator): bool
    {
        return in_array($operator, ['is_empty', 'is_not_empty', 'is_checked', 'is_unchecked'], true);
    }
}
