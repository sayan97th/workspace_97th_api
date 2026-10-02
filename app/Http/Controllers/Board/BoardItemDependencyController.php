<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\UpdateBoardItemDependencyLinksRequest;
use App\Http\Resources\BoardItemResource;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardDependencyScheduler;
use App\Services\Board\ColumnPermissionService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * A Dependency cell's links: which items this item depends on, and how far before or after each
 * one it is scheduled. See {@see BoardDependencyScheduler}.
 */
class BoardItemDependencyController extends Controller
{
    public function __construct(
        private readonly BoardDependencyScheduler $scheduler,
        private readonly ColumnPermissionService $column_permissions,
    ) {}

    /**
     * PUT /api/boards/{item}/items/{board_item}/dependencies/{column}
     *
     * Replaces the cell's links and moves the item's date where they put it. Responds with the
     * item and every other item whose date moved along with it.
     */
    public function update(UpdateBoardItemDependencyLinksRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column): JsonResponse
    {
        abort_if($board_item->board_id !== $item->id || $column->board_id !== $item->id, 404);
        abort_if($column->type !== BoardColumn::TYPE_DEPENDENCY, 422, 'This column is not a dependency column.');

        $board_item->loadMissing('group');
        abort_if($board_item->group?->board_view_id !== $column->board_view_id, 404);
        $scope = $board_item->parent_id === null ? BoardColumn::SCOPE_ITEM : BoardColumn::SCOPE_SUBITEM;
        abort_if($column->scope !== $scope, 422, 'This column belongs to another kind of row.');

        BoardEditGate::authorizeItem($item, $request->user(), $board_item);
        $this->column_permissions->authorizeEdit($item, $request->user(), [$column->id]);

        $links = $request->validated()['links'];
        $this->ensureValidPredecessors($board_item, $column, $scope, array_map(fn (array $link) => (int) $link['predecessor_id'], $links));

        $this->scheduler->setLinks($item, $board_item, $column, $links, $request->user());

        return response()->json([
            'message' => 'Dependencies updated successfully.',
            'item' => new BoardItemResource($board_item->fresh(['values', 'dependencyLinks'])),
            'moved_items' => BoardItemResource::collection($this->scheduler->movedItems($board_item->id)),
        ]);
    }

    /**
     * Every predecessor must be another row of the same table and kind (item or subitem), and
     * none may already depend on this item, which would make a loop.
     *
     * @param  array<int, int>  $predecessor_ids
     */
    private function ensureValidPredecessors(BoardItem $board_item, BoardColumn $column, string $scope, array $predecessor_ids): void
    {
        if ($predecessor_ids === []) {
            return;
        }

        $found = BoardItem::whereIn('id', $predecessor_ids)
            ->where('board_id', $board_item->board_id)
            ->when($scope === BoardColumn::SCOPE_ITEM, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->whereNotNull('parent_id'))
            ->whereHas('group', fn ($query) => $query->where('board_view_id', $column->board_view_id))
            ->pluck('id')
            ->all();

        $missing = array_diff($predecessor_ids, $found);
        if ($missing !== []) {
            throw ValidationException::withMessages(['links' => 'Some of these items are not on this table.']);
        }

        foreach ($predecessor_ids as $predecessor_id) {
            if ($this->scheduler->wouldCreateCycle($column, $board_item, $predecessor_id)) {
                throw ValidationException::withMessages(['links' => 'An item cannot depend on itself or on an item that already depends on it.']);
            }
        }
    }
}
