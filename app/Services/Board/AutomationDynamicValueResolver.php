<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\FeedFollow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a dynamic value into the value it stands for on one run, so an automation can say "assign
 * the person who made the change", "set Due date to today + 3 days" or "only if Budget is more than
 * Cost" instead of a fixed person, date or number. No AI involved, every source is a fixed rule.
 *
 * A dynamic value is `{source, offset_days?, use_working_days?, column_id?}`, `source` one of
 * {@see BoardAutomation::DYNAMIC_SOURCES}:
 *
 * - `actor`: whoever set the automation off. `creator`: whoever created the item. `owner`: whoever
 *   answers for the automation. `mentioned`: the person a "someone is mentioned" trigger fired for.
 * - `today`: today plus `offset_days`, counting working days of the board with `use_working_days`.
 * - `column`: what the column `column_id` holds on the item (on the parent for an item column read
 *   from a subitem), plus `offset_days` for a date.
 *
 * It is read the way the target stores its value: people as a list of ids, a date as `YYYY-MM-DD`,
 * a number as a number and anything else as the text the board shows.
 */
class AutomationDynamicValueResolver
{
    private const PEOPLE_TYPES = [BoardColumn::TYPE_PEOPLE, BoardColumn::TYPE_VOTE];

    private const DATE_TYPES = [BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE];

    private const NUMBER_TYPES = [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS, BoardColumn::TYPE_AUTO_NUMBER, BoardColumn::TYPE_TIME_TRACKING];

    private const TEXT_TYPES = [
        BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_EMAIL, BoardColumn::TYPE_PHONE, BoardColumn::TYPE_LINK,
        BoardColumn::TYPE_MIRROR, BoardColumn::TYPE_FORMULA, BoardColumn::TYPE_CHECKLIST, BoardColumn::TYPE_FILES,
    ];

    /** Item details a condition reads as people, dates, numbers or text, see {@see AutomationConditionEvaluator::VIRTUAL_FIELDS}. */
    private const VIRTUAL_FAMILIES = [
        '__created_by__' => 'people',
        '__actor__' => 'people',
        '__created_at__' => 'date',
        '__updated_at__' => 'date',
        '__update_count__' => 'number',
        '__subitem_count__' => 'number',
        'name' => 'text',
    ];

    public function __construct(private readonly BoardAutomationMessageRenderer $renderer) {}

    /**
     * Whether `$value` is a dynamic value the builder saved.
     */
    public static function isDynamic(mixed $value): bool
    {
        return is_array($value) && in_array($value['source'] ?? null, BoardAutomation::DYNAMIC_SOURCES, true);
    }

    /**
     * The people ids a person source stands for, empty when it names nobody right now.
     *
     * @param  array<string, mixed>  $dynamic
     * @param  array<string, mixed>  $context  what the trigger knows, `mentioned_user_id` for a mention
     * @return array<int, string>
     */
    public function personIds(array $dynamic, ?BoardItem $item, ?User $actor, BoardAutomation $automation, array $context = []): array
    {
        $id = match ($dynamic['source'] ?? null) {
            'actor' => $actor?->id,
            'creator' => $item?->created_by_id,
            'owner' => $automation->responsibleUser()?->id,
            'mentioned' => isset($context['mentioned_user_id']) ? (int) $context['mentioned_user_id'] : null,
            default => null,
        };
        if ($id !== null) {
            return [(string) $id];
        }

        $column = ($dynamic['source'] ?? null) === 'column' ? $this->sourceColumn($dynamic, $automation) : null;
        if ($item === null || ! $column || ! in_array($column->type, self::PEOPLE_TYPES, true)) {
            return [];
        }

        $value = $this->valueOn($item, $column);

        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    /**
     * The value to write into `$target`, in the shape its cells store it. The first entry says
     * whether the source could be read at all.
     *
     * @param  array<string, mixed>  $dynamic
     * @param  array<string, mixed>  $context
     * @return array{0: bool, 1: mixed}
     */
    public function forColumn(array $dynamic, BoardColumn $target, BoardItem $item, ?User $actor, BoardAutomation $automation, array $context = []): array
    {
        if (in_array($target->type, self::PEOPLE_TYPES, true)) {
            $ids = $this->personIds($dynamic, $item, $actor, $automation, $context);

            return [$ids !== [], $ids];
        }

        if ($target->type === BoardColumn::TYPE_DATE) {
            $date = $this->date($dynamic, $item, $automation);

            return [$date !== null, $date];
        }

        if (in_array($target->type, [BoardColumn::TYPE_NUMBER, BoardColumn::TYPE_RATING, BoardColumn::TYPE_PROGRESS], true)) {
            $number = $this->number($dynamic, $item, $automation);
            if ($number === null) {
                return [false, null];
            }
            if ($target->type === BoardColumn::TYPE_PROGRESS) {
                $number = max(0, min(100, $number));
            }
            if ($target->type === BoardColumn::TYPE_RATING) {
                $number = max(0, min(5, round($number)));
            }

            return [true, floor($number) === $number ? (int) $number : round($number, 6)];
        }

        if (in_array($target->type, [BoardColumn::TYPE_TEXT, BoardColumn::TYPE_LONG_TEXT, BoardColumn::TYPE_EMAIL, BoardColumn::TYPE_PHONE], true)) {
            $text = $this->text($dynamic, $item, $actor, $automation, $context);

            return [$text !== null && $text !== '', $text];
        }

        return [false, null];
    }

    /**
     * A condition rule with its dynamic value read into `value`/`values`, or null when the source
     * names nothing right now (the rule then fails). A rule without a dynamic value comes back as is.
     *
     * @param  array<string, mixed>  $rule
     * @param  Collection<string, BoardColumn>  $columns  every column of the tab keyed by id
     * @return array<string, mixed>|null
     */
    public function forRule(array $rule, BoardItem $item, ?User $actor, BoardAutomation $automation, Collection $columns): ?array
    {
        $dynamic = $rule['dynamic'] ?? null;
        if (! self::isDynamic($dynamic)) {
            return $rule;
        }

        switch ($this->ruleFamily((string) ($rule['column_id'] ?? ''), $columns)) {
            case 'people':
                $ids = $this->personIds($dynamic, $item, $actor, $automation);

                return $ids === [] ? null : [...$rule, 'value' => '', 'values' => $ids];
            case 'date':
                $date = $this->date($dynamic, $item, $automation);

                return $date === null ? null : [...$rule, 'value' => $date, 'values' => []];
            case 'number':
                $number = $this->number($dynamic, $item, $automation);

                return $number === null ? null : [...$rule, 'value' => (string) $number, 'values' => []];
            case 'text':
                $text = $this->text($dynamic, $item, $actor, $automation);

                return $text === null ? null : [...$rule, 'value' => $text, 'values' => []];
            default:
                return null;
        }
    }

    /**
     * What family a condition field compares as: `people`, `date`, `number`, `text`, or null for
     * the fields a dynamic value cannot stand for (labels, groups, checkboxes).
     *
     * @param  Collection<string, BoardColumn>  $columns
     */
    public function ruleFamily(string $field_id, Collection $columns): ?string
    {
        if (isset(self::VIRTUAL_FAMILIES[$field_id])) {
            return self::VIRTUAL_FAMILIES[$field_id];
        }

        return $this->columnFamily($columns->get($field_id));
    }

    public function columnFamily(?BoardColumn $column): ?string
    {
        return match (true) {
            $column === null => null,
            in_array($column->type, self::PEOPLE_TYPES, true) => 'people',
            in_array($column->type, self::DATE_TYPES, true) => 'date',
            in_array($column->type, self::NUMBER_TYPES, true) => 'number',
            in_array($column->type, self::TEXT_TYPES, true) => 'text',
            default => null,
        };
    }

    /**
     * A day as `YYYY-MM-DD`: today, or the date a column holds (the end of a timeline), moved by `offset_days`.
     *
     * @param  array<string, mixed>  $dynamic
     */
    private function date(array $dynamic, ?BoardItem $item, BoardAutomation $automation): ?string
    {
        $offset = (int) ($dynamic['offset_days'] ?? 0);

        $base = null;
        if (($dynamic['source'] ?? null) === 'today') {
            $base = CarbonImmutable::today();
        } elseif (($dynamic['source'] ?? null) === 'column' && $item !== null) {
            $column = $this->sourceColumn($dynamic, $automation);
            $value = $column ? $this->valueOn($item, $column) : null;
            $raw = $column?->type === BoardColumn::TYPE_TIMELINE ? (is_array($value) ? ($value['end'] ?? $value['start'] ?? null) : null) : $value;
            $day = is_string($raw) ? substr($raw, 0, 10) : '';
            $base = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::parse($day) : null;
        }
        if ($base === null) {
            return null;
        }

        if ($offset !== 0 && ! empty($dynamic['use_working_days'])) {
            return BoardAutomationSetting::forBoard($automation->board_id)->calendar()->addWorkingDays($base, $offset)->toDateString();
        }

        return $base->addDays($offset)->toDateString();
    }

    /**
     * The number a column holds, a time tracking column in hours.
     *
     * @param  array<string, mixed>  $dynamic
     */
    private function number(array $dynamic, ?BoardItem $item, BoardAutomation $automation): ?float
    {
        $column = ($dynamic['source'] ?? null) === 'column' && $item !== null ? $this->sourceColumn($dynamic, $automation) : null;
        if (! $column) {
            return null;
        }

        $value = $this->valueOn($item, $column);
        if ($column->type === BoardColumn::TYPE_TIME_TRACKING) {
            return is_array($value) && isset($value['seconds']) ? round(((int) $value['seconds']) / 3600, 4) : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A name for the person sources, or what a column shows as text.
     *
     * @param  array<string, mixed>  $dynamic
     * @param  array<string, mixed>  $context
     */
    private function text(array $dynamic, ?BoardItem $item, ?User $actor, BoardAutomation $automation, array $context = []): ?string
    {
        $source = $dynamic['source'] ?? null;
        if (in_array($source, ['actor', 'creator', 'owner', 'mentioned'], true)) {
            $ids = $this->personIds($dynamic, $item, $actor, $automation, $context);

            return $ids === [] ? null : User::whereKey((int) $ids[0])->first()?->full_name;
        }

        $column = $source === 'column' && $item !== null ? $this->sourceColumn($dynamic, $automation) : null;
        if (! $column) {
            return null;
        }

        return $this->renderer->displayValue($column, $this->valueOn($item, $column));
    }

    /**
     * @param  array<string, mixed>  $dynamic
     */
    private function sourceColumn(array $dynamic, BoardAutomation $automation): ?BoardColumn
    {
        $column_id = $dynamic['column_id'] ?? null;

        return is_numeric($column_id) ? BoardColumn::where('board_view_id', $automation->board_view_id)->find((int) $column_id) : null;
    }

    /**
     * What `$column` holds for `$item`: its own value when the scopes match, its top-level parent's
     * for an item column read from a subitem, nothing for a subitem column read from an item.
     */
    private function valueOn(BoardItem $item, BoardColumn $column): mixed
    {
        $subject = $item;
        if ($column->scope === BoardColumn::SCOPE_ITEM) {
            while ($subject->parent_id !== null && ($parent = BoardItem::find($subject->parent_id))) {
                $subject = $parent;
            }
        } elseif ($item->parent_id === null) {
            return null;
        }

        return BoardItemValue::where('item_id', $subject->id)->where('column_id', $column->id)->first()?->value;
    }

    /**
     * Everyone subscribed to `$item`: its followers and the followers of its whole board, active
     * accounts only.
     *
     * @return Collection<int, User>
     */
    public function subscribers(BoardItem $item): Collection
    {
        $user_ids = FeedFollow::query()
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner->where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id))
                ->orWhere(fn ($inner) => $inner->where('target_type', FeedFollow::TYPE_BOARD)->where('target_id', $item->board_id)))
            ->pluck('user_id')
            ->unique();

        return $user_ids->isEmpty() ? new Collection : User::whereIn('id', $user_ids)->where('is_active', true)->get();
    }
}
