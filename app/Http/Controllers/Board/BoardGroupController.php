<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\DuplicateBoardGroupRequest;
use App\Http\Requests\Board\MoveBoardGroupRequest;
use App\Http\Requests\Board\StoreBoardGroupRequest;
use App\Http\Requests\Board\UpdateBoardGroupRequest;
use App\Http\Requests\Board\UpdateGroupCollapseStateRequest;
use App\Http\Resources\BoardGroupResource;
use App\Models\BoardGroup;
use App\Models\BoardGroupCollapseState;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardGroupController extends Controller
{
    public function __construct(private readonly BoardViewResolver $view_resolver) {}

    /**
     * GET /api/boards/{item}/groups
     *
     * A tab's groups are its "tables" — any number (1…N) can exist, each
     * rendered as its own titled table by the frontend's `BoardTable`. Scoped
     * to one tab — `view_id` if given, otherwise the board's primary tab.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));

        $collapsed_group_ids = $view
            ? BoardGroupCollapseState::where('user_id', $request->user()?->id)
                ->where('board_view_id', $view->id)
                ->value('collapsed_group_ids')
            : null;

        return response()->json([
            'data' => BoardGroupResource::collection($view?->groups ?? collect()),
            'collapsed_group_ids' => $collapsed_group_ids ?? [],
        ]);
    }

    /**
     * POST /api/boards/{item}/groups
     */
    public function store(StoreBoardGroupRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $view = $this->view_resolver->resolveForWrite($item, $validated['view_id'] ?? null);

        // An explicit `position` (the group menu's "Add group", which lands
        // the new table directly below the one the menu was opened on) puts
        // the new group in the middle of the sequence, so every group
        // already at or past that slot has to shift up first — exactly like
        // `BoardColumnController::store()`'s own "Add column to the right".
        // Omitting `position` (the toolbar's "Add new group" button) just
        // appends.
        if (array_key_exists('position', $validated)) {
            $position = $validated['position'];

            BoardGroup::where('board_view_id', $view->id)
                ->where('position', '>=', $position)
                ->increment('position');
        } else {
            $position = $this->nextPosition($view);
        }

        $group = $view->groups()->create([
            'board_id' => $item->id,
            'name' => $validated['name'],
            'accent_color' => $validated['accent_color'] ?? '#579bfc',
            'is_priority' => $validated['is_priority'] ?? false,
            'position' => $position,
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'group' => new BoardGroupResource($group),
        ], 201);
    }

    /**
     * PUT /api/boards/{item}/groups/collapsed-state
     *
     * Saves which of this tab's tables the authenticated viewer currently has
     * collapsed — a personal preference, not shared with other collaborators
     * (mirrors {@see BoardViewController::updatePersonalOrder()}).
     * Only the collapsed ids are ever stored/sent, so this stays a small
     * payload regardless of how many tables the tab has.
     */
    public function updateCollapsedState(UpdateGroupCollapseStateRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForWrite($item, $request->validated('view_id'));

        $group_ids = $view->groups()->pluck('id');
        $collapsed_group_ids = collect($request->validated('collapsed_group_ids'))
            ->filter(fn (int $id) => $group_ids->contains($id))
            ->values()
            ->all();

        $state = BoardGroupCollapseState::updateOrCreate(
            ['user_id' => $request->user()?->id, 'board_view_id' => $view->id],
            ['collapsed_group_ids' => $collapsed_group_ids],
        );

        return response()->json([
            'message' => 'Collapsed tables saved successfully.',
            'collapsed_group_ids' => $state->collapsed_group_ids,
        ]);
    }

    /**
     * PATCH /api/boards/{item}/groups/{group}
     */
    public function update(UpdateBoardGroupRequest $request, WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->fill($request->validated())->save();

        return response()->json([
            'message' => 'Table updated successfully.',
            'group' => new BoardGroupResource($group->fresh()),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/groups/{group}/move
     */
    public function move(MoveBoardGroupRequest $request, WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->position = $request->validated()['position'];
        $group->save();

        return response()->json([
            'message' => 'Table moved successfully.',
            'group' => new BoardGroupResource($group->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/groups/{group}
     *
     * Cascades to the group's items (see the `board_items` migration).
     */
    public function destroy(WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $group->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/groups/{group}/duplicate
     *
     * Group menu's "Duplicate this group" — clones the table itself (name
     * suffixed " copy") appended at the end of the tab's group order, and
     * when `with_items` is true, deep-copies its entire item/subitem subtree
     * with column values (mirroring {@see BoardItemController::copySubtree()},
     * without the "(copy)" per-item name suffix since the group's own name
     * already marks the whole table as a duplicate).
     */
    public function duplicate(DuplicateBoardGroupRequest $request, WorkspaceNavigationItem $item, BoardGroup $group): JsonResponse
    {
        $this->ensureGroupBelongsToBoard($item, $group);

        $copy = $group->replicate();
        $copy->name = "{$group->name} copy";
        $copy->position = $this->nextPosition($group->boardView);
        $copy->save();

        if ($request->boolean('with_items')) {
            $originals = $group->items()->whereNull('parent_id')->with(['values', 'childrenRecursive'])->orderBy('position')->get();

            foreach ($originals as $original) {
                $this->copyItemSubtree($item, $original, $copy->id, null);
            }
        }

        return response()->json([
            'message' => 'Table duplicated successfully.',
            'group' => new BoardGroupResource($copy),
        ], 201);
    }

    /**
     * Guard: abort with 404 when the group is not part of the board.
     */
    private function ensureGroupBelongsToBoard(WorkspaceNavigationItem $item, BoardGroup $group): void
    {
        abort_if($group->board_id !== $item->id, 404);
    }

    /**
     * Recursively deep-copy an item and its subtree into `$group_id`.
     */
    private function copyItemSubtree(WorkspaceNavigationItem $item, BoardItem $original, int $group_id, ?int $parent_id): void
    {
        $copy = $item->items()->create([
            'group_id' => $group_id,
            'parent_id' => $parent_id,
            'name' => $original->name,
            'description' => $original->description,
            'position' => $original->position,
            'created_by_id' => $original->created_by_id,
        ]);

        foreach ($original->values as $value) {
            $copy->values()->create([
                'column_id' => $value->column_id,
                'value' => $value->value,
            ]);
        }

        foreach ($original->childrenRecursive as $child) {
            $this->copyItemSubtree($item, $child, $group_id, $copy->id);
        }
    }

    /**
     * The next free position among the tab's groups (append to the end).
     */
    private function nextPosition(BoardView $view): int
    {
        return (int) $view->groups()->max('position') + 1;
    }

    /**
     * Reads `view_id` from the query string for GET requests.
     */
    private function viewIdParam(Request $request): ?int
    {
        return $request->filled('view_id') ? (int) $request->query('view_id') : null;
    }
}
