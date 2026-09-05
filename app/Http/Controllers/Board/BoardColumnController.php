<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\DuplicateBoardColumnRequest;
use App\Http\Requests\Board\MoveBoardColumnRequest;
use App\Http\Requests\Board\ReorderBoardColumnsRequest;
use App\Http\Requests\Board\StoreBoardColumnRequest;
use App\Http\Requests\Board\UpdateBoardColumnRequest;
use App\Http\Resources\BoardColumnResource;
use App\Models\BoardColumn;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BoardColumnController extends Controller
{
    public function __construct(private readonly BoardViewResolver $view_resolver) {}

    /**
     * GET /api/boards/{item}/columns
     *
     * Scoped to one tab — `view_id` if given, otherwise the board's primary
     * tab. Returns an empty list when that tab doesn't exist yet. Returns
     * both item- and subitem-scoped columns together by default (the
     * frontend splits them by `scope` once, since most callers need both);
     * pass `scope` to fetch only one set.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForRead($item, $this->viewIdParam($request));
        $columns = $view?->columns ?? collect();

        if ($request->filled('scope')) {
            $columns = $columns->where('scope', $request->query('scope'))->values();
        }

        return response()->json([
            'data' => BoardColumnResource::collection($columns),
        ]);
    }

    /**
     * POST /api/boards/{item}/columns
     */
    public function store(StoreBoardColumnRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $view = $this->view_resolver->resolveForWrite($item, $validated['view_id'] ?? null);
        $scope = $validated['scope'] ?? BoardColumn::SCOPE_ITEM;

        // An explicit `position` (the column menu's "Add column to the
        // right") lands the new column in the middle of the sequence, so —
        // exactly like `duplicate()` below — every column already at or past
        // that slot has to shift up first. Otherwise it would collide with
        // whichever column already sits there, leaving their order
        // ambiguous. Omitting `position` (the "+" gallery) just appends.
        if (array_key_exists('position', $validated)) {
            $position = $validated['position'];

            BoardColumn::where('board_view_id', $view->id)
                ->where('scope', $scope)
                ->where('position', '>=', $position)
                ->increment('position');
        } else {
            $position = $this->nextPosition($view, $scope);
        }

        $column = $view->columns()->create([
            'board_id' => $item->id,
            'key' => $validated['key'],
            'label' => $validated['label'],
            'type' => $validated['type'],
            'scope' => $scope,
            'position' => $position,
            'width' => $validated['width'] ?? 180,
            'config' => $validated['config'] ?? $this->defaultConfigFor($validated['type']),
            'hideable' => $validated['hideable'] ?? true,
            'pinnable' => $validated['pinnable'] ?? true,
        ]);

        return response()->json([
            'message' => 'Column created successfully.',
            'column' => new BoardColumnResource($column),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/columns/{column}
     */
    public function update(UpdateBoardColumnRequest $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureColumnBelongsToBoard($item, $column);

        $column->fill($request->validated())->save();

        return response()->json([
            'message' => 'Column updated successfully.',
            'column' => new BoardColumnResource($column->fresh()),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/columns/{column}/move
     */
    public function move(MoveBoardColumnRequest $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureColumnBelongsToBoard($item, $column);

        $column->position = $request->validated()['position'];
        $column->save();

        return response()->json([
            'message' => 'Column moved successfully.',
            'column' => new BoardColumnResource($column->fresh()),
        ]);
    }

    /**
     * PATCH /api/boards/{item}/columns/reorder
     *
     * Column-header drag-and-drop reordering (main table header or subitem
     * header) — resequences every column in `ordered_ids` to its index in
     * that list, scoped to one tab (`view_id`, defaulting to the primary
     * tab) and one column `scope`, mirroring
     * {@see \App\Http\Controllers\Board\BoardItemController::reorder()}. Any
     * id that doesn't actually belong to the resolved view+scope is quietly
     * dropped rather than rejected outright, so a stale client-side column
     * list can't fail the whole drag. Declared before the `{column}`
     * wildcard routes in `routes/api.php` so the literal "reorder" segment
     * isn't swallowed by route-model binding.
     */
    public function reorder(ReorderBoardColumnsRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $view = $this->view_resolver->resolveForWrite($item, $validated['view_id'] ?? null);
        $scope = $validated['scope'];

        $scoped_column_ids = BoardColumn::where('board_view_id', $view->id)
            ->where('scope', $scope)
            ->pluck('id');

        $ordered_ids = collect($validated['ordered_ids'])
            ->filter(fn (int $id) => $scoped_column_ids->contains($id))
            ->values();

        DB::transaction(function () use ($view, $scope, $ordered_ids) {
            foreach ($ordered_ids as $position => $id) {
                BoardColumn::where('id', $id)
                    ->where('board_view_id', $view->id)
                    ->where('scope', $scope)
                    ->update(['position' => $position]);
            }
        });

        $columns = BoardColumn::where('board_view_id', $view->id)
            ->where('scope', $scope)
            ->orderBy('position')
            ->get();

        return response()->json([
            'message' => 'Columns reordered successfully.',
            'data' => BoardColumnResource::collection($columns),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/columns/{column}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureColumnBelongsToBoard($item, $column);

        $column->delete();

        return response()->json([
            'message' => 'Column deleted successfully.',
        ]);
    }

    /**
     * POST /api/boards/{item}/columns/{column}/duplicate
     *
     * Column menu's "Duplicate column" — clones the column definition
     * (freshly-generated unique `key`, label suffixed " copy") immediately
     * after the original, and optionally clones every item's stored value
     * for it too.
     */
    public function duplicate(DuplicateBoardColumnRequest $request, WorkspaceNavigationItem $item, BoardColumn $column): JsonResponse
    {
        $this->ensureColumnBelongsToBoard($item, $column);

        $target_position = $column->position + 1;

        BoardColumn::where('board_view_id', $column->board_view_id)
            ->where('scope', $column->scope)
            ->where('position', '>=', $target_position)
            ->increment('position');

        $copy = $column->replicate(['key']);
        $copy->key = $this->uniqueKeyFor($column);
        $copy->label = "{$column->label} copy";
        $copy->position = $target_position;
        $copy->save();

        if ($request->boolean('with_values')) {
            foreach ($column->values as $value) {
                $copy->values()->create([
                    'item_id' => $value->item_id,
                    'value' => $value->value,
                ]);
            }
        }

        return response()->json([
            'message' => 'Column duplicated successfully.',
            'column' => new BoardColumnResource($copy),
        ], 201);
    }

    /**
     * Guard: abort with 404 when the column is not part of the board.
     */
    private function ensureColumnBelongsToBoard(WorkspaceNavigationItem $item, BoardColumn $column): void
    {
        abort_if($column->board_id !== $item->id, 404);
    }

    /**
     * The next free position among the tab's columns of the given scope
     * (append to the end) — item and subitem columns each have their own
     * independent position sequence.
     */
    private function nextPosition(BoardView $view, string $scope): int
    {
        return (int) $view->columns()->where('scope', $scope)->max('position') + 1;
    }

    /**
     * Reads `view_id` from the query string for GET requests.
     */
    private function viewIdParam(Request $request): ?int
    {
        return $request->filled('view_id') ? (int) $request->query('view_id') : null;
    }

    /**
     * Sensible starting `config` for a freshly created column when the caller
     * didn't supply one. Status/Label columns get Monday-style default
     * labels so a new column is immediately usable; every other type
     * (including Progress, a plain 0-100 number under the hood) starts
     * blank.
     *
     * @return array<string, mixed>|null
     */
    private function defaultConfigFor(string $type): ?array
    {
        if ($type === BoardColumn::TYPE_STATUS) {
            return [
                'options' => [
                    ['id' => (string) Str::uuid(), 'label' => 'Working on it', 'color' => '#fdab3d', 'is_active' => true],
                    ['id' => (string) Str::uuid(), 'label' => 'Done', 'color' => '#00c875', 'is_active' => true],
                    ['id' => (string) Str::uuid(), 'label' => 'Stuck', 'color' => '#e2445c', 'is_active' => true],
                ],
            ];
        }

        if ($type === BoardColumn::TYPE_LABEL) {
            return [
                'options' => [
                    ['id' => (string) Str::uuid(), 'label' => 'Low', 'color' => '#5b6180', 'is_active' => true],
                    ['id' => (string) Str::uuid(), 'label' => 'Medium', 'color' => '#8a6d1f', 'is_active' => true],
                    ['id' => (string) Str::uuid(), 'label' => 'High', 'color' => '#5b3fbd', 'is_active' => true],
                    ['id' => (string) Str::uuid(), 'label' => 'Critical', 'color' => '#b02f43', 'is_active' => true],
                ],
            ];
        }

        return null;
    }

    /**
     * A fresh, unique `key` for a duplicated column — the original's key
     * suffixed with "_copy" (then "_copy_2", "_copy_3", ...) until it's
     * unique within the same tab+scope, matching the uniqueness rule `key`
     * is validated against on creation.
     */
    private function uniqueKeyFor(BoardColumn $original): string
    {
        $base = Str::limit($original->key, 90, '');
        $n = 1;

        do {
            $candidate = $n === 1 ? "{$base}_copy" : "{$base}_copy_{$n}";
            $n++;
        } while (BoardColumn::where('board_view_id', $original->board_view_id)
            ->where('scope', $original->scope)
            ->where('key', $candidate)
            ->exists());

        return $candidate;
    }
}
