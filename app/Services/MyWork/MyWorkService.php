<?php

namespace App\Services\MyWork;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Services\Board\ColumnPermissionService;
use App\Support\BoardEditGate;
use Illuminate\Support\Collection;

/**
 * monday.com's "My Work": every item, across every board, that has the user
 * in one of its People columns. Each row carries the item's first Status
 * and first Date (or Timeline end) so the page can bucket it by date and
 * edit both inline through the regular item values endpoint.
 */
class MyWorkService
{
    /** Status labels that count as finished work. */
    private const DONE_LABELS = ['done', 'complete', 'completed', 'finished', 'closed'];

    /** monday.com's "Done" green. */
    private const DONE_COLOR = '#00c875';

    /** Upper bound so a very busy account still gets a fast response. */
    private const MAX_ITEMS = 500;

    public function __construct(private readonly ColumnPermissionService $column_permissions) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, status_columns: array<string, array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $item_ids = $this->assignedItemIds($user);

        if ($item_ids === []) {
            return ['items' => [], 'status_columns' => []];
        }

        $items = BoardItem::query()
            ->whereIn('id', $item_ids)
            ->where('is_archived', false)
            ->whereHas('group', fn ($query) => $query->where('is_archived', false))
            ->whereHas('board', fn ($query) => $query->where('is_archived', false))
            ->with(['values', 'group:id,name,accent_color,board_view_id', 'board:id,label,workspace_id,edit_permission,created_by_id,owner_id', 'board.workspace:id,name,slug,color,mono', 'parent:id,name'])
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        $columns_by_view = $this->columnsByViewAndScope($items);
        $status_columns = [];
        $level_by_board = [];

        $rows = $items->map(function (BoardItem $item) use ($user, $columns_by_view, &$status_columns, &$level_by_board) {
            $scope = $item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
            $columns = $columns_by_view[$item->group->board_view_id.':'.$scope] ?? collect();
            $hidden_ids = $this->column_permissions->hiddenColumnIds($item->board_id, $user);
            $columns = $columns->reject(fn (BoardColumn $column) => in_array($column->id, $hidden_ids, true));
            $values = $item->values->keyBy('column_id');

            $status_column = $columns->firstWhere('type', BoardColumn::TYPE_STATUS);
            $date_column = $columns->firstWhere('type', BoardColumn::TYPE_DATE) ?? $columns->firstWhere('type', BoardColumn::TYPE_TIMELINE);
            $checkbox_column = $columns->firstWhere('type', BoardColumn::TYPE_CHECKBOX);

            $status = null;
            if ($status_column) {
                $status_columns[(string) $status_column->id] ??= [
                    'id' => $status_column->id,
                    'label' => $status_column->label,
                    'options' => collect($status_column->config['options'] ?? [])
                        ->filter(fn ($option) => ($option['is_active'] ?? true) !== false)
                        ->map(fn ($option) => ['id' => (string) $option['id'], 'label' => $option['label'], 'color' => $option['color']])
                        ->values(),
                ];
                $status = $this->resolveStatus($status_column, $values->get($status_column->id)?->value);
            }

            $date = $date_column ? $this->resolveDate($date_column, $values->get($date_column->id)?->value) : null;
            $is_checked = $checkbox_column ? $values->get($checkbox_column->id)?->value === true : false;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'parent' => $item->parent ? ['id' => $item->parent->id, 'name' => $item->parent->name] : null,
                'board' => [
                    'id' => $item->board->id,
                    'label' => $item->board->label,
                    'workspace' => $item->board->workspace ? [
                        'id' => $item->board->workspace->id,
                        'name' => $item->board->workspace->name,
                        'color' => $item->board->workspace->color,
                        'mono' => $item->board->workspace->mono,
                    ] : null,
                ],
                'group' => ['id' => $item->group->id, 'name' => $item->group->name, 'color' => $item->group->accent_color],
                'status_column_id' => $status_column?->id,
                'status' => $status,
                'date_column' => $date_column ? ['id' => $date_column->id, 'type' => $date_column->type] : null,
                'date' => $date,
                'is_done' => $is_checked || ($status !== null && $this->isDoneStatus($status)),
                // Every row here is assigned to the user, so even the
                // "assigned items only" permission lets them edit it.
                'can_edit' => ($level_by_board[$item->board_id] ??= BoardEditGate::level($item->board, $user)) !== BoardEditGate::LEVEL_NONE,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ];
        });

        return ['items' => $rows->values()->all(), 'status_columns' => $status_columns];
    }

    /**
     * Ids of every item whose People columns include `$user`. People values
     * are JSON arrays of user ids, so a coarse `LIKE` narrows the rows in SQL
     * (portable across MySQL and SQLite) and the exact match runs in PHP.
     *
     * @return array<int, int>
     */
    private function assignedItemIds(User $user): array
    {
        $people_column_ids = BoardColumn::query()->where('type', BoardColumn::TYPE_PEOPLE)->pluck('id');

        if ($people_column_ids->isEmpty()) {
            return [];
        }

        $user_id = (string) $user->id;

        return BoardItemValue::query()
            ->whereIn('column_id', $people_column_ids)
            ->where('value', 'like', "%{$user_id}%")
            ->get(['item_id', 'value'])
            ->filter(fn (BoardItemValue $value) => is_array($value->value) && in_array($user_id, array_map('strval', $value->value), true))
            ->pluck('item_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every column of the tabs these items live on, keyed by `"{view_id}:{scope}"`.
     *
     * @param  Collection<int, BoardItem>  $items
     * @return array<string, Collection<int, BoardColumn>>
     */
    private function columnsByViewAndScope(Collection $items): array
    {
        $view_ids = $items->pluck('group.board_view_id')->unique()->filter()->values();

        return BoardColumn::query()
            ->whereIn('board_view_id', $view_ids)
            ->whereIn('type', [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_DATE, BoardColumn::TYPE_TIMELINE, BoardColumn::TYPE_CHECKBOX])
            ->orderBy('position')
            ->get()
            ->groupBy(fn (BoardColumn $column) => $column->board_view_id.':'.$column->scope)
            ->all();
    }

    /**
     * @return array{id: string, label: string, color: string}|null
     */
    private function resolveStatus(BoardColumn $column, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $option = collect($column->config['options'] ?? [])
            ->first(fn ($option) => (string) $option['id'] === (string) $value || $option['label'] === $value);

        return $option ? ['id' => (string) $option['id'], 'label' => $option['label'], 'color' => $option['color']] : null;
    }

    /**
     * The date an item is due: a Date column's value, or a Timeline's end.
     *
     * @return array{value: string, start: string|null}|null
     */
    private function resolveDate(BoardColumn $column, mixed $value): ?array
    {
        if ($column->type === BoardColumn::TYPE_DATE) {
            return is_string($value) && $value !== '' ? ['value' => substr($value, 0, 10), 'start' => null] : null;
        }

        if (is_array($value) && ! empty($value['end'])) {
            return ['value' => (string) $value['end'], 'start' => $value['start'] ?? null];
        }

        // Older Timeline values were stored as a "start..end" string.
        if (is_string($value) && str_contains($value, '..')) {
            [$start, $end] = explode('..', $value, 2);

            return $end !== '' ? ['value' => $end, 'start' => $start ?: null] : null;
        }

        return null;
    }

    /**
     * @param  array{label: string, color: string}  $status
     */
    private function isDoneStatus(array $status): bool
    {
        return in_array(strtolower(trim($status['label'])), self::DONE_LABELS, true)
            || strtolower($status['color']) === self::DONE_COLOR;
    }
}
