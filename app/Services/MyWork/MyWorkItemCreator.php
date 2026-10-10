<?php

namespace App\Services\MyWork;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationService;
use App\Services\Board\BoardItemValueService;
use App\Services\Board\BoardViewResolver;
use App\Services\Board\ColumnPermissionService;
use App\Support\BoardEditGate;
use App\Support\VisibleBoards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * My Work's "New item" and "+ Add item": creates an item on a board the user
 * picks, at the top of the board's first group (or the group picked in the
 * dialog), assigned to the user in the board's first People column and, when
 * a date is given, due on that date, so the new row shows up in My Work
 * right away.
 */
class MyWorkItemCreator
{
    /** Upper bound for the board picker. */
    private const MAX_BOARDS = 300;

    public function __construct(
        private readonly BoardViewResolver $view_resolver,
        private readonly BoardItemValueService $value_service,
        private readonly ColumnPermissionService $column_permissions,
        private readonly BoardAutomationService $automation_service,
    ) {}

    /**
     * Boards the user can open and add items to, for the board picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function boardsFor(User $user): array
    {
        return VisibleBoards::query($user)
            ->boards()
            ->with('workspace:id,name,color,mono')
            ->orderBy('label')
            ->limit(self::MAX_BOARDS)
            ->get()
            ->filter(fn (WorkspaceNavigationItem $board) => BoardEditGate::allowsContent($board, $user))
            ->map(fn (WorkspaceNavigationItem $board) => [
                'id' => $board->id,
                'label' => $board->label,
                'workspace' => $board->workspace ? [
                    'id' => $board->workspace->id,
                    'name' => $board->workspace->name,
                    'color' => $board->workspace->color,
                    'mono' => $board->workspace->mono,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * What the "New Item" dialog shows for a board: its groups, plus the
     * People, date, Status and Priority columns the user can fill in.
     *
     * @return array<string, mixed>
     */
    public function formFor(User $user, WorkspaceNavigationItem $board): array
    {
        BoardEditGate::authorizeContent($board, $user);

        $view = $this->view_resolver->resolveForWrite($board, null);
        $columns = $this->editableColumns($user, $board, $view->id);
        $people_column = $columns->firstWhere('type', BoardColumn::TYPE_PEOPLE);
        $date_column = $this->dateColumnOf($columns);

        return [
            'groups' => $this->groupsQuery($board, $view->id)
                ->get(['id', 'name', 'accent_color'])
                ->map(fn (BoardGroup $group) => ['id' => $group->id, 'name' => $group->name, 'color' => $group->accent_color])
                ->values()
                ->all(),
            'people_column' => $people_column ? ['id' => $people_column->id, 'label' => $people_column->label] : null,
            'date_column' => $date_column ? ['id' => $date_column->id, 'label' => $date_column->label, 'type' => $date_column->type] : null,
            'status_column' => $this->statusColumnPayload($this->statusColumnOf($columns, false)),
            'priority_column' => $this->statusColumnPayload($this->statusColumnOf($columns, true)),
        ];
    }

    /**
     * `$choices` holds the optional picks of the "New Item" dialog. A group
     * from another board or an unknown status option is rejected.
     *
     * @param  array{group_id?: int|null, status?: string|null, priority?: string|null}  $choices
     */
    public function create(User $user, WorkspaceNavigationItem $board, string $name, ?string $date, array $choices = []): BoardItem
    {
        BoardEditGate::authorizeContent($board, $user);

        $view = $this->view_resolver->resolveForWrite($board, null);
        $group_id = $choices['group_id'] ?? null;
        $groups = $this->groupsQuery($board, $view->id);
        $group = $group_id !== null ? $groups->whereKey($group_id)->first() : $groups->first();

        if ($group === null) {
            throw ValidationException::withMessages($group_id !== null
                ? ['group_id' => 'That group is not on this board.']
                : ['board_id' => 'This board has no group to add the item to.']);
        }

        $columns = $this->editableColumns($user, $board, $view->id);
        $people_column = $columns->firstWhere('type', BoardColumn::TYPE_PEOPLE);
        $date_column = $this->dateColumnOf($columns);

        $values = [];
        if ($people_column) {
            $values[(string) $people_column->id] = [$user->id];
        }
        if ($date_column && $date !== null) {
            // Same "start..end" form the Table view's Timeline cell writes.
            $values[(string) $date_column->id] = $date_column->type === BoardColumn::TYPE_TIMELINE ? "{$date}..{$date}" : $date;
        }

        $status_choices = [
            'status' => $this->statusColumnOf($columns, false),
            'priority' => $this->statusColumnOf($columns, true),
        ];
        foreach ($status_choices as $key => $column) {
            $option_id = $choices[$key] ?? null;
            if ($option_id === null || $option_id === '') {
                continue;
            }
            if ($column === null || ! $this->hasActiveOption($column, (string) $option_id)) {
                throw ValidationException::withMessages([$key => 'That option is not on this board.']);
            }
            $values[(string) $column->id] = (string) $option_id;
        }

        // New items go on top of the group, like monday.com's My Work.
        $board_item = DB::transaction(function () use ($board, $group, $name, $user) {
            $board->items()->where('group_id', $group->id)->whereNull('parent_id')->increment('position');

            return $board->items()->create([
                'group_id' => $group->id,
                'parent_id' => null,
                'name' => $name,
                'position' => 0,
                'created_by_id' => $user->id,
            ]);
        });

        $this->value_service->assignAutoNumbers($board_item, BoardColumn::SCOPE_ITEM);

        if ($values !== []) {
            $this->value_service->sync($board, $board_item, $values, $user, false);
        }

        $this->automation_service->handleItemCreated($board_item, $user);

        return $board_item;
    }

    /**
     * The board's active groups on the view items are written to, in board order.
     *
     * @return Builder<BoardGroup>
     */
    private function groupsQuery(WorkspaceNavigationItem $board, int $view_id): Builder
    {
        return BoardGroup::query()
            ->where('board_id', $board->id)
            ->where('board_view_id', $view_id)
            ->where('is_archived', false)
            ->orderBy('position');
    }

    /**
     * Item columns the user is allowed to write, in board order.
     *
     * @return Collection<int, BoardColumn>
     */
    private function editableColumns(User $user, WorkspaceNavigationItem $board, int $view_id): Collection
    {
        return BoardColumn::query()
            ->where('board_view_id', $view_id)
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->orderBy('position')
            ->get()
            ->filter(fn (BoardColumn $column) => $this->column_permissions->canEdit($column, $user, $board))
            ->values();
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function dateColumnOf(Collection $columns): ?BoardColumn
    {
        return $columns->firstWhere('type', BoardColumn::TYPE_DATE) ?? $columns->firstWhere('type', BoardColumn::TYPE_TIMELINE);
    }

    /**
     * The first Status column that is (or is not) a Priority column, the same
     * pick My Work makes for its Status and Priority columns.
     *
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function statusColumnOf(Collection $columns, bool $is_priority): ?BoardColumn
    {
        return $columns
            ->where('type', BoardColumn::TYPE_STATUS)
            ->first(fn (BoardColumn $column) => MyWorkService::isPriorityColumn($column) === $is_priority);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function statusColumnPayload(?BoardColumn $column): ?array
    {
        if ($column === null) {
            return null;
        }

        return [
            'id' => $column->id,
            'label' => $column->label,
            'options' => $this->activeOptions($column)
                ->map(fn ($option) => ['id' => (string) $option['id'], 'label' => $option['label'], 'color' => $option['color']])
                ->values()
                ->all(),
        ];
    }

    private function hasActiveOption(BoardColumn $column, string $option_id): bool
    {
        return $this->activeOptions($column)->contains(fn ($option) => (string) $option['id'] === $option_id);
    }

    /**
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function activeOptions(BoardColumn $column): SupportCollection
    {
        return collect($column->config['options'] ?? [])->filter(fn ($option) => ($option['is_active'] ?? true) !== false);
    }
}
