<?php

namespace App\Http\Controllers\Board;

use App\Enums\BoardViewType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewRequest;
use App\Http\Requests\Board\UpdateBoardViewRequest;
use App\Http\Requests\Board\UpdatePersonalViewOrderRequest;
use App\Http\Resources\BoardViewResource;
use App\Models\BoardView;
use App\Models\BoardViewUserOrder;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardViewController extends Controller
{
    /**
     * GET /api/boards/{item}/views
     *
     * A view row is both a tab (`label`/`position`) and a saved filter
     * configuration for that tab (`filter_state`/`sort_state`/etc).
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensurePrimaryViewExists($item);

        $personal_order = BoardViewUserOrder::where('user_id', $request->user()?->id)
            ->where('board_id', $item->id)
            ->value('view_order');

        return response()->json([
            'data' => BoardViewResource::collection($item->views),
            'personal_order' => $personal_order,
        ]);
    }

    /**
     * Guarantees every board has a primary tab to scope its content into —
     * created lazily on first `views` load rather than at board-creation
     * time, so boards created before per-tab content scoping existed still
     * get one. Content-index endpoints (`BoardColumnController`,
     * `BoardGroupController`, `BoardItemController`) resolve to an empty
     * list until this has run at least once for a given board.
     */
    private function ensurePrimaryViewExists(WorkspaceNavigationItem $item): void
    {
        if ($item->views()->where('is_primary', true)->exists()) {
            return;
        }

        $item->views()->create([
            'label' => 'Main table',
            'view_type' => BoardViewType::Table->value,
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ]);
    }

    /**
     * POST /api/boards/{item}/views
     */
    public function store(StoreBoardViewRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $view = $item->views()->create([
            ...$validated,
            'view_type' => $validated['view_type'] ?? BoardViewType::Table->value,
            'position' => $validated['position'] ?? $this->nextPosition($item),
            'is_primary' => $validated['is_primary'] ?? false,
            'row_height' => $validated['row_height'] ?? 'single',
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'View created successfully.',
            'view' => new BoardViewResource($view),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/views/{board_view}
     *
     * This is the "save filters for this board view" endpoint — called with
     * any subset of the saved-state fields that changed.
     */
    public function update(UpdateBoardViewRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        $board_view->fill($request->validated())->save();

        return response()->json([
            'message' => 'View saved successfully.',
            'view' => new BoardViewResource($board_view->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        if ($board_view->is_primary) {
            return response()->json([
                'message' => 'The primary view cannot be deleted.',
            ], 422);
        }

        $board_view->delete();

        return response()->json([
            'message' => 'View deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/duplicate
     *
     * Clones a view's label + saved filter/sort/display configuration into a
     * new, always-unlocked, non-primary tab appended to the end — and,
     * because columns/groups/items are scoped per tab (see
     * `BelongsToBoardView`), also deep-clones every table (group), column,
     * item and cell value the source tab owns, so the new tab is a genuine,
     * independently-editable copy rather than an empty shell. Comments/
     * activity on the source items are intentionally NOT copied — a
     * duplicate is a fresh structural copy to edit, not a copy of the
     * conversation history.
     */
    public function duplicate(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureViewUnlocked($board_view);

        $board_view->loadMissing(['columns', 'groups.items.values']);

        $copy = DB::transaction(function () use ($request, $item, $board_view) {
            $column_id_map = [];

            $view_copy = $item->views()->create([
                'label' => "{$board_view->label} (copy)",
                'view_type' => $board_view->view_type,
                'icon' => $board_view->icon,
                'position' => $this->nextPosition($item),
                'is_primary' => false,
                'pinned' => false,
                'is_locked' => false,
                'locked_by_id' => null,
                'row_height' => $board_view->row_height,
                'created_by_id' => $request->user()?->id,
            ]);

            foreach ($board_view->columns as $column) {
                $column_copy = $view_copy->columns()->create([
                    'board_id' => $item->id,
                    'key' => $column->key,
                    'label' => $column->label,
                    'type' => $column->type,
                    'position' => $column->position,
                    'width' => $column->width,
                    'config' => $column->config,
                    'hideable' => $column->hideable,
                    'pinnable' => $column->pinnable,
                ]);
                $column_id_map[$column->id] = $column_copy->id;
            }

            foreach ($board_view->groups as $group) {
                $group_copy = $view_copy->groups()->create([
                    'board_id' => $item->id,
                    'name' => $group->name,
                    'accent_color' => $group->accent_color,
                    'position' => $group->position,
                ]);

                foreach ($group->items as $source_item) {
                    $item_copy = $item->items()->create([
                        'group_id' => $group_copy->id,
                        'name' => $source_item->name,
                        'position' => $source_item->position,
                        'created_by_id' => $source_item->created_by_id,
                    ]);

                    foreach ($source_item->values as $source_value) {
                        $target_column_id = $column_id_map[$source_value->column_id] ?? null;
                        if ($target_column_id === null) {
                            continue;
                        }

                        $item_copy->values()->create([
                            'column_id' => $target_column_id,
                            'value' => $source_value->value,
                        ]);
                    }
                }
            }

            // The saved filter/sort/display state references the *source*
            // tab's column ids — remapped onto the freshly cloned columns so
            // the copy's toolbar (hidden/pinned columns, filters, sort,
            // group-by, conditional colors) still works.
            $view_copy->fill($this->remapColumnReferences($board_view, $column_id_map))->save();

            return $view_copy;
        });

        return response()->json([
            'message' => 'View duplicated successfully.',
            'view' => new BoardViewResource($copy->fresh()),
        ], 201);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/pin
     *
     * Toggles whether the tab is pinned (sorts ahead of unpinned tabs).
     * Allowed even while locked — pinning doesn't touch the view's saved
     * filter content.
     */
    public function togglePin(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $board_view->update(['pinned' => ! $board_view->pinned]);

        return response()->json([
            'message' => $board_view->pinned ? 'View pinned successfully.' : 'View unpinned successfully.',
            'view' => new BoardViewResource($board_view),
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/lock
     *
     * Toggles the view's lock. While locked, rename/delete/duplicate and
     * saving filter/sort/display changes are all blocked (see `update()`,
     * `destroy()`, `duplicate()`) until any collaborator unlocks it again.
     */
    public function toggleLock(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $is_locked = ! $board_view->is_locked;
        $board_view->update([
            'is_locked' => $is_locked,
            'locked_by_id' => $is_locked ? $request->user()?->id : null,
        ]);

        return response()->json([
            'message' => $is_locked ? 'View locked successfully.' : 'View unlocked successfully.',
            'view' => new BoardViewResource($board_view),
        ]);
    }

    /**
     * PUT /api/boards/{item}/views/order
     *
     * Saves the authenticated user's personal "Reorder (for you only)" tab
     * order for this board — doesn't touch the shared `position`/`pinned`
     * columns other collaborators see.
     */
    public function updatePersonalOrder(UpdatePersonalViewOrderRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $board_view_ids = $item->views()->pluck('id');
        $view_order = collect($request->validated('view_ids'))
            ->filter(fn (int $id) => $board_view_ids->contains($id))
            ->values()
            ->all();

        $order = BoardViewUserOrder::updateOrCreate(
            ['user_id' => $request->user()?->id, 'board_id' => $item->id],
            ['view_order' => $view_order],
        );

        return response()->json([
            'message' => 'View order saved successfully.',
            'personal_order' => $order->view_order,
        ]);
    }

    /**
     * Guard: abort with 404 when the view is not part of the board.
     */
    private function ensureViewBelongsToBoard(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 423 (Locked) when the view is locked — blocks
     * rename/delete/duplicate and saving filter/sort/display changes.
     */
    private function ensureViewUnlocked(BoardView $board_view): void
    {
        abort_if($board_view->is_locked, 423, 'This view is locked and can\'t be edited. Unlock it first.');
    }

    /**
     * The next free position among the board's views (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->views()->max('position') + 1;
    }

    /**
     * Rebuilds every saved-state field that references a column id
     * (`filter_state.search_column_ids`/`.advanced_filter_rows[].column_id`/
     * `.quick_filter_selections` keys, `sort_state[].sort_option_id`,
     * `hidden_column_ids`, `pinned_column_ids`,
     * `conditional_color_rules[].column_id`, `group_by_option_id`) so a
     * duplicated tab's saved filters/sort/columns/grouping point at its own
     * freshly cloned columns instead of the source tab's.
     *
     * @param  array<int, int>  $column_id_map  source column id => copy column id
     * @return array<string, mixed>
     */
    private function remapColumnReferences(BoardView $source, array $column_id_map): array
    {
        $filter_state = $source->filter_state;
        if ($filter_state) {
            $filter_state['search_column_ids'] = $this->remapIdList($filter_state['search_column_ids'] ?? [], $column_id_map);

            $filter_state['advanced_filter_rows'] = collect($filter_state['advanced_filter_rows'] ?? [])
                ->map(fn (array $row) => [
                    ...$row,
                    'column_id' => $this->remapId($row['column_id'] ?? null, $column_id_map),
                ])
                ->all();

            $filter_state['quick_filter_selections'] = collect($filter_state['quick_filter_selections'] ?? [])
                ->mapWithKeys(fn ($option_ids, $facet_id) => [$this->remapId((string) $facet_id, $column_id_map) => $option_ids])
                ->all();
        }

        $sort_state = $source->sort_state === null ? null : collect($source->sort_state)
            ->map(fn (array $rule) => [
                ...$rule,
                'sort_option_id' => $this->remapId($rule['sort_option_id'] ?? null, $column_id_map),
            ])
            ->all();

        $conditional_color_rules = $source->conditional_color_rules === null ? null : collect($source->conditional_color_rules)
            ->map(fn (array $rule) => [
                ...$rule,
                'column_id' => $this->remapId($rule['column_id'] ?? null, $column_id_map),
            ])
            ->all();

        return [
            'filter_state' => $filter_state,
            'sort_state' => $sort_state,
            'hidden_column_ids' => $source->hidden_column_ids === null ? null : $this->remapIdList($source->hidden_column_ids, $column_id_map),
            'pinned_column_ids' => $source->pinned_column_ids === null ? null : $this->remapIdList($source->pinned_column_ids, $column_id_map),
            'conditional_color_rules' => $conditional_color_rules,
            'group_by_option_id' => $this->remapId($source->group_by_option_id, $column_id_map),
        ];
    }

    /**
     * @param  array<int, int>  $column_id_map
     * @return array<int, string|null>
     */
    private function remapIdList(array $ids, array $column_id_map): array
    {
        return array_map(fn ($id) => $this->remapId($id, $column_id_map), $ids);
    }

    /**
     * Remaps a single column-id reference. Non-numeric values (e.g. the
     * `"name"` sort sentinel or the `"default"` group-by sentinel) are left
     * untouched, as is any id with no corresponding entry in the map.
     *
     * @param  array<int, int>  $column_id_map
     */
    private function remapId(?string $id, array $column_id_map): ?string
    {
        if ($id === null || ! ctype_digit($id)) {
            return $id;
        }

        return isset($column_id_map[(int) $id]) ? (string) $column_id_map[(int) $id] : $id;
    }
}
