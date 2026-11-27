<?php

namespace App\Http\Controllers\Board;

use App\Enums\BoardViewType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Board\DuplicateBoardViewRequest;
use App\Http\Requests\Board\StoreBoardViewRequest;
use App\Http\Requests\Board\UpdateBoardViewPersonalStateRequest;
use App\Http\Requests\Board\UpdateBoardViewRequest;
use App\Http\Requests\Board\UpdatePersonalViewOrderRequest;
use App\Http\Requests\Board\UpdatePersonalViewPreferencesRequest;
use App\Http\Resources\BoardViewResource;
use App\Models\BoardView;
use App\Models\BoardViewUserOrder;
use App\Models\BoardViewUserState;
use App\Models\BoardVisit;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardDuplicationService;
use App\Services\Board\ChartDataService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $preferences = BoardViewUserOrder::where('user_id', $request->user()?->id)
            ->where('board_id', $item->id)
            ->first();

        $views = $item->views()->with('creator')->get();

        // The viewer's remembered, unsaved changes per view ("Remember my filters").
        $personal_states = BoardViewUserState::query()
            ->where('user_id', $request->user()?->id)
            ->whereIn('board_view_id', $views->pluck('id'))
            ->get()
            ->mapWithKeys(fn (BoardViewUserState $state) => [(string) $state->board_view_id => $state->toPayload()]);

        return response()->json([
            'data' => BoardViewResource::collection($views),
            'personal_order' => $preferences?->view_order,
            // Tabs the viewer hid for themselves, and the tab they want opened first.
            'personal_hidden_view_ids' => $preferences?->hidden_view_ids ?? [],
            'personal_default_view_id' => $preferences?->default_view_id,
            // An object even when empty, so the client never receives a list here.
            'personal_states' => (object) $personal_states->all(),
        ]);
    }

    /**
     * Guarantees every board has a primary tab to scope its content into —
     * created lazily on first `views` load rather than at board-creation
     * time, so boards created before per-tab content scoping existed still
     * get one. Content-index endpoints (`BoardColumnController`,
     * `BoardGroupController`, `BoardItemController`) resolve to an empty
     * list until this has run at least once for a given board.
     *
     * Deliberately does not seed any starter `BoardColumn` rows: the item's
     * own name is a built-in field, not a column, so a brand-new tab has no
     * columns at all until the user explicitly adds one (see
     * {@see BoardColumnController::store()}) or imports a file that creates
     * them (see {@see BoardImportController}).
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
        BoardEditGate::authorizeStructure($item, $request->user());

        $validated = $request->validated();
        $view_type = BoardViewType::from($validated['view_type'] ?? BoardViewType::Table->value);

        $view = $item->views()->create([
            ...$validated,
            'view_type' => $view_type->value,
            'source_view_id' => $this->sourceViewIdForNewView($item, $view_type, $validated),
            'position' => $validated['position'] ?? $this->nextPosition($item),
            'is_primary' => $validated['is_primary'] ?? false,
            'row_height' => $validated['row_height'] ?? 'single',
            'created_by_id' => $request->user()?->id,
        ]);
        $view->setRelation('creator', $request->user());

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
            'view' => new BoardViewResource($board_view->fresh(['creator'])),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        BoardEditGate::authorizeStructure($item, $request->user());
        $this->ensureViewUnlocked($board_view);

        if ($board_view->is_primary) {
            return response()->json([
                'message' => 'The primary view cannot be deleted.',
            ], 422);
        }

        // Filter views would lose every item they show, so they go first.
        $filter_view_count = $board_view->filterViews()->count();
        if ($filter_view_count > 0) {
            return response()->json([
                'message' => $filter_view_count === 1
                    ? 'Another view shows this view\'s items. Delete that view first.'
                    : "{$filter_view_count} other views show this view's items. Delete those views first.",
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
     * new, always-unlocked, non-primary tab appended to the end. A Table,
     * Gantt or Calendar tab (and any filter view) is copied as a filter view
     * of the same data, like monday.com, so the copy shows the very same
     * items. Only a tab that owns separate data (a Kanban) is deep cloned
     * with its tables, columns, items and cell values. Comments/activity are
     * never copied.
     *
     * The toolbar's "Save as new view" sends a `label` and the live
     * filter/sort/display state, which the copy keeps instead of the source's
     * saved state (column and group ids remapped onto the copy's own). That
     * leaves the source untouched, so it is allowed from a locked tab too.
     */
    public function duplicate(DuplicateBoardViewRequest $request, WorkspaceNavigationItem $item, BoardView $board_view, BoardDuplicationService $duplication_service): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        BoardEditGate::authorizeStructure($item, $request->user());

        $state_overrides = $request->stateOverrides();
        if ($state_overrides === []) {
            $this->ensureViewUnlocked($board_view);
        }

        $overrides = [
            'label' => $request->validated('label') ?? "{$board_view->label} (copy)",
            'position' => $this->nextPosition($item),
        ];

        // A copy of a tab that shows items is a filter view of the same data,
        // as on monday.com; only a Kanban tab with its own items is deep copied.
        $view_type = BoardViewType::tryFrom($board_view->view_type);
        $is_filter_copy = $view_type !== null
            && $view_type->canShowItems()
            && ($board_view->isFilterView() || $board_view->is_primary || $view_type->isFilterViewByDefault());

        $copy = $is_filter_copy
            ? $duplication_service->duplicateAsFilterView($board_view, $item, $overrides, $request->user()?->id, $state_overrides)
            : $duplication_service->duplicateView($board_view, $item, $overrides, $request->user()?->id, $state_overrides);

        return response()->json([
            'message' => 'View duplicated successfully.',
            'view' => new BoardViewResource($copy->fresh(['creator'])),
        ], 201);
    }

    /**
     * GET /api/boards/{item}/views/{board_view}/chart-data
     *
     * Computed chart series for a `chart`-type view — see
     * {@see ChartDataService} for how a chart tab's own (empty) content is
     * bypassed in favor of aggregating another tab's items.
     */
    public function chartData(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        return response()->json((new ChartDataService)->build($item, $board_view));
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
            'view' => new BoardViewResource($board_view->load('creator')),
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
            'view' => new BoardViewResource($board_view->load('creator')),
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
     * DELETE /api/boards/{item}/views/order
     *
     * "Reset to default order": forgets the viewer's personal tab order, so the
     * shared `position`/`pinned` ordering applies again. Hidden tabs and the
     * default tab are kept.
     */
    public function destroyPersonalOrder(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardViewUserOrder::where('user_id', $request->user()?->id)
            ->where('board_id', $item->id)
            ->update(['view_order' => null]);

        return response()->json([
            'message' => 'View order reset successfully.',
            'personal_order' => null,
        ]);
    }

    /**
     * PUT /api/boards/{item}/views/preferences
     *
     * Saves the viewer's own tab preferences for this board: which tabs are
     * hidden for them and which tab opens first. Ids that don't belong to the
     * board are dropped, and at least one tab always stays visible.
     */
    public function updatePersonalPreferences(UpdatePersonalViewPreferencesRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $board_view_ids = $item->views()->pluck('id');
        $attributes = [];

        if ($request->has('hidden_view_ids')) {
            $hidden_view_ids = collect($request->validated('hidden_view_ids'))
                ->filter(fn (int $id) => $board_view_ids->contains($id))
                ->unique()
                ->values();

            abort_if(
                $board_view_ids->isNotEmpty() && $hidden_view_ids->count() >= $board_view_ids->count(),
                422,
                'At least one view must stay visible.',
            );

            $attributes['hidden_view_ids'] = $hidden_view_ids->all();
        }

        if ($request->has('default_view_id')) {
            $default_view_id = $request->validated('default_view_id');
            $attributes['default_view_id'] = $default_view_id !== null && $board_view_ids->contains($default_view_id)
                ? $default_view_id
                : null;
        }

        $preferences = BoardViewUserOrder::updateOrCreate(
            ['user_id' => $request->user()?->id, 'board_id' => $item->id],
            $attributes,
        );

        return response()->json([
            'message' => 'View preferences saved successfully.',
            'personal_hidden_view_ids' => $preferences->hidden_view_ids ?? [],
            'personal_default_view_id' => $preferences->default_view_id,
        ]);
    }

    /**
     * PUT /api/boards/{item}/views/{board_view}/personal-state
     *
     * "Remember my filters": stores the viewer's unsaved filter, sort, hidden
     * columns and group by for this view, replayed the next time they open it
     * (on any device). Only affects the viewer, so it needs no edit access and
     * works on a locked view too.
     */
    public function updatePersonalState(UpdateBoardViewPersonalStateRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        abort_unless($request->user()->workspaces()->where('workspaces.id', $item->workspace_id)->exists(), 403);

        $state = BoardViewUserState::updateOrCreate(
            ['user_id' => $request->user()->id, 'board_view_id' => $board_view->id],
            $request->safe()->only(['filter_state', 'sort_state', 'hidden_column_ids', 'group_by_option_id']),
        );

        return response()->json([
            'message' => 'View changes remembered.',
            'personal_state' => $state->toPayload(),
        ]);
    }

    /**
     * PUT /api/boards/{item}/views/{board_view}/visit
     *
     * Remembers the tab the viewer has open, so the Home page's "Recently
     * visited" card previews that tab (a Kanban, a Gantt, a Chart, …).
     */
    public function recordVisit(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        BoardVisit::recordView($request->user()->id, $item->id, $board_view->id);

        return response()->json(['message' => 'Visit recorded.']);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}/personal-state
     *
     * "Reset to view": forgets the viewer's remembered changes to this view.
     */
    public function destroyPersonalState(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        BoardViewUserState::query()
            ->where('user_id', $request->user()->id)
            ->where('board_view_id', $board_view->id)
            ->delete();

        return response()->json(['message' => 'View reset to its saved state.']);
    }

    /**
     * Which tab a new tab shows the items of. An explicit `source_view_id`
     * wins (null means "own, empty data"), resolved to the tab that stores the
     * content so filter views never chain. Otherwise Table, Gantt and Calendar
     * tabs show the Main table, like every board view on monday.com.
     *
     * @param  array<string, mixed>  $validated
     */
    private function sourceViewIdForNewView(WorkspaceNavigationItem $item, BoardViewType $view_type, array $validated): ?int
    {
        if (! $view_type->canShowItems() || ($validated['is_primary'] ?? false)) {
            return null;
        }

        if (array_key_exists('source_view_id', $validated)) {
            if ($validated['source_view_id'] === null) {
                return null;
            }

            $source = $item->views()->find($validated['source_view_id']);
            $source_type = $source ? BoardViewType::tryFrom($source->view_type) : null;
            abort_if($source === null || $source_type === null || ! $source_type->canShowItems(), 422, 'Pick a view with items to show.');

            return $source->content_view_id;
        }

        if (! $view_type->isFilterViewByDefault()) {
            return null;
        }

        $this->ensurePrimaryViewExists($item);

        return $item->views()->where('is_primary', true)->value('id');
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
}
