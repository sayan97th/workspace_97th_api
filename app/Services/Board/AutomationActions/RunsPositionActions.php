<?php

namespace App\Services\Board\AutomationActions;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\User;
use App\Services\Board\BoardAutomationActionOutcome;
use App\Services\Board\BoardAutomationActionRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The position actions of {@see BoardAutomationActionRunner}: move the item to the top or bottom of
 * its group, and sort a whole group by a column. Both write the order the same way a drag and drop
 * does (`position` counts from 0), and the order before the run is kept in the run's journal so
 * the run can be undone.
 */
trait RunsPositionActions
{
    /** Most items one "sort group" orders, a larger group is left as it is. */
    private const MAX_SORTED_ITEMS = 2000;

    /**
     * @param  array<string, mixed>  $params
     */
    private function moveItemPosition(BoardAutomation $automation, array $params, BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $is_top = ($params['position'] ?? 'top') === 'top';
        $where = $is_top ? 'top' : 'bottom';
        $order = $this->siblingsQuery($item)->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $others = array_values(array_filter($order, fn (int $id) => $id !== $item->id));
        $next = $is_top ? [$item->id, ...$others] : [...$others, $item->id];

        if ($next === $order) {
            return BoardAutomationActionOutcome::skipped("The item was already at the {$where} of its ".($item->parent_id === null ? 'group' : 'subitems').'.');
        }

        $this->writeOrder($order, $next);
        $this->journal->reordered($item->board_id, $item->id, $order, $next);

        $place = $item->parent_id === null ? '"'.($item->group?->name ?? 'its group').'"' : 'its subitems';
        $this->log($automation, $item, $actor, "moved \"{$item->name}\" to the {$where} of {$place}");

        return BoardAutomationActionOutcome::success("Moved the item to the {$where} of {$place}.");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function sortGroup(BoardAutomation $automation, array $params, ?BoardItem $item, ?User $actor): BoardAutomationActionOutcome
    {
        $group = $this->resolveGroupParam($automation, $params, $item, 'target_group_id');
        if (is_string($group)) {
            return BoardAutomationActionOutcome::skipped($group);
        }

        $sort_by = (string) ($params['sort_by'] ?? 'name');
        $column = null;
        if ($sort_by === 'column') {
            $column = BoardColumn::where('board_view_id', $group->board_view_id)->where('scope', BoardColumn::SCOPE_ITEM)->find((int) ($params['sort_column_id'] ?? 0));
            if (! $column) {
                return BoardAutomationActionOutcome::failed('The column to sort by no longer exists.');
            }
        }

        $items = BoardItem::where('group_id', $group->id)->whereNull('parent_id')->where('is_archived', false)
            ->orderBy('position')->orderBy('id')
            ->with('values')
            ->limit(self::MAX_SORTED_ITEMS + 1)
            ->get();
        if ($items->count() > self::MAX_SORTED_ITEMS) {
            return BoardAutomationActionOutcome::skipped('The group has more than '.self::MAX_SORTED_ITEMS.' items, too many to sort automatically.');
        }

        $order = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $next = $this->sortedIds($items, $sort_by, $column, ($params['direction'] ?? 'asc') === 'desc');
        $label = $column?->label ?? ($sort_by === 'created_at' ? 'creation date' : 'name');

        if ($next === $order) {
            return BoardAutomationActionOutcome::skipped("\"{$group->name}\" was already sorted by {$label}.");
        }

        $this->writeOrder($order, $next);
        $this->journal->reordered($group->board_id, $item?->id, $order, $next);
        $this->log($automation, $item, $actor, "sorted \"{$group->name}\" by {$label}");

        return BoardAutomationActionOutcome::success('Sorted the '.count($next)." items of \"{$group->name}\" by {$label}.");
    }

    /**
     * The item's siblings, itself included: the top level items of its group, or its parent's subitems.
     *
     * @return Builder<BoardItem>
     */
    private function siblingsQuery(BoardItem $item): Builder
    {
        return $item->parent_id === null
            ? BoardItem::where('group_id', $item->group_id)->whereNull('parent_id')->where('is_archived', false)
            : BoardItem::where('parent_id', $item->parent_id)->where('is_archived', false);
    }

    /**
     * Writes `$next` as the new order, touching only the items whose position changes.
     *
     * @param  array<int, int>  $order  the ids in their current order
     * @param  array<int, int>  $next  the same ids in the new order
     */
    private function writeOrder(array $order, array $next): void
    {
        $current = BoardItem::whereIn('id', $order)->pluck('position', 'id');

        DB::transaction(function () use ($next, $current) {
            foreach ($next as $position => $id) {
                if ((int) ($current[$id] ?? -1) !== $position) {
                    BoardItem::whereKey($id)->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * The ids of `$items` sorted by `$sort_by`. Empty values go last in both directions, and items
     * with the same value keep their current order.
     *
     * @param  Collection<int, BoardItem>  $items  in their current order
     * @return array<int, int>
     */
    private function sortedIds(Collection $items, string $sort_by, ?BoardColumn $column, bool $is_descending): array
    {
        $people_names = $column?->type === BoardColumn::TYPE_PEOPLE ? $this->peopleNames($items, $column) : collect();

        $keyed = $items->values()->map(fn (BoardItem $entry, int $index) => [
            'id' => (int) $entry->id,
            'index' => $index,
            'key' => match ($sort_by) {
                'created_at' => $entry->created_at?->getTimestamp(),
                'column' => $this->sortKey($column, $entry, $people_names),
                default => mb_strtolower(trim((string) $entry->name)),
            },
        ])->all();

        usort($keyed, function (array $a, array $b) use ($is_descending) {
            $a_empty = $a['key'] === null || $a['key'] === '';
            $b_empty = $b['key'] === null || $b['key'] === '';
            if ($a_empty || $b_empty) {
                return $a_empty === $b_empty ? $a['index'] <=> $b['index'] : ($a_empty ? 1 : -1);
            }

            $compared = is_string($a['key']) || is_string($b['key'])
                ? strnatcasecmp((string) $a['key'], (string) $b['key'])
                : $a['key'] <=> $b['key'];

            return ($is_descending ? -$compared : $compared) ?: $a['index'] <=> $b['index'];
        });

        return array_map(fn (array $entry) => $entry['id'], $keyed);
    }

    /**
     * What an item sorts by in `$column`, a number or a lower case text, null when it is empty.
     * Labels sort in the order the column lists them, like monday.com.
     *
     * @param  Collection<int, string>  $people_names
     */
    private function sortKey(?BoardColumn $column, BoardItem $item, Collection $people_names): float|int|string|null
    {
        if ($column === null) {
            return null;
        }
        $value = $item->values->firstWhere('column_id', $column->id)?->value;
        $count = fn (mixed $list): ?int => is_array($list) && $list !== [] ? count($list) : null;

        switch ($column->type) {
            case BoardColumn::TYPE_STATUS:
            case BoardColumn::TYPE_LABEL:
                $index = collect($column->config['options'] ?? [])->search(fn ($option) => (string) ($option['id'] ?? '') === (string) $value);

                return $value === null || $value === '' || $index === false ? null : (int) $index;
            case BoardColumn::TYPE_NUMBER:
            case BoardColumn::TYPE_RATING:
            case BoardColumn::TYPE_PROGRESS:
            case BoardColumn::TYPE_AUTO_NUMBER:
                return is_numeric($value) ? (float) $value : null;
            case BoardColumn::TYPE_CHECKBOX:
                return $value === true || $value === 'true' || $value === 1 || $value === '1' ? 1 : 0;
            case BoardColumn::TYPE_DATE:
                return is_string($value) && $value !== '' ? $value : null;
            case BoardColumn::TYPE_TIMELINE:
                return is_array($value) ? ($value['start'] ?? $value['end'] ?? null) : null;
            case BoardColumn::TYPE_PEOPLE:
                $first = is_array($value) ? ($value[0] ?? null) : null;

                return $first === null ? null : ($people_names[(int) $first] ?? null);
            case BoardColumn::TYPE_VOTE:
            case BoardColumn::TYPE_FILES:
            case BoardColumn::TYPE_CONNECT_BOARD:
            case BoardColumn::TYPE_DEPENDENCY:
                return $count($value);
            case BoardColumn::TYPE_CHECKLIST:
                $tasks = array_filter((array) ($value ?? []), 'is_array');

                return $tasks === [] ? null : count(array_filter($tasks, fn (array $task) => ! empty($task['is_done']))) / count($tasks);
            case BoardColumn::TYPE_TIME_TRACKING:
                return is_array($value) && is_numeric($value['seconds'] ?? null) ? (float) $value['seconds'] : null;
            case BoardColumn::TYPE_FORMULA:
                $outcome = $this->formula_resolver->outcome($column, $item);
                if ($outcome === null || ! $outcome['ok']) {
                    return null;
                }

                return is_int($outcome['value']) || is_float($outcome['value']) ? (float) $outcome['value'] : (mb_strtolower($outcome['text']) ?: null);
            default:
                $text = mb_strtolower(trim($this->renderer->displayValue($column, $value)));

                return $text === '' ? null : $text;
        }
    }

    /**
     * The full names of the first person of every item, lower case, keyed by user id.
     *
     * @param  Collection<int, BoardItem>  $items
     * @return Collection<int, string>
     */
    private function peopleNames(Collection $items, BoardColumn $column): Collection
    {
        $ids = $items->map(fn (BoardItem $item) => $item->values->firstWhere('column_id', $column->id)?->value)
            ->filter(fn ($value) => is_array($value) && isset($value[0]) && is_numeric($value[0]))
            ->map(fn (array $value) => (int) $value[0])
            ->unique()
            ->values();

        return User::whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user) => [$user->id => mb_strtolower(trim($user->first_name.' '.$user->last_name))]);
    }
}
