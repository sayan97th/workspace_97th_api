<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\MoveBoardColumnRequest;
use App\Http\Requests\Board\StoreBoardColumnRequest;
use App\Http\Requests\Board\UpdateBoardColumnRequest;
use App\Http\Resources\BoardColumnResource;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BoardColumnController extends Controller
{
    /**
     * GET /api/boards/{item}/columns
     */
    public function index(WorkspaceNavigationItem $item): JsonResponse
    {
        return response()->json([
            'data' => BoardColumnResource::collection($item->columns),
        ]);
    }

    /**
     * POST /api/boards/{item}/columns
     */
    public function store(StoreBoardColumnRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $column = $item->columns()->create([
            'key' => $validated['key'],
            'label' => $validated['label'],
            'type' => $validated['type'],
            'position' => $validated['position'] ?? $this->nextPosition($item),
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
     * Guard: abort with 404 when the column is not part of the board.
     */
    private function ensureColumnBelongsToBoard(WorkspaceNavigationItem $item, BoardColumn $column): void
    {
        abort_if($column->board_id !== $item->id, 404);
    }

    /**
     * The next free position among the board's columns (append to the end).
     */
    private function nextPosition(WorkspaceNavigationItem $item): int
    {
        return (int) $item->columns()->max('position') + 1;
    }

    /**
     * Sensible starting `config` for a freshly created column when the caller
     * didn't supply one. Status columns get Monday-style default labels so a
     * new column is immediately usable; every other type starts blank.
     *
     * @return array<string, mixed>|null
     */
    private function defaultConfigFor(string $type): ?array
    {
        if ($type !== BoardColumn::TYPE_STATUS) {
            return null;
        }

        return [
            'options' => [
                ['id' => (string) Str::uuid(), 'label' => 'Working on it', 'color' => '#fdab3d', 'is_active' => true],
                ['id' => (string) Str::uuid(), 'label' => 'Done', 'color' => '#00c875', 'is_active' => true],
                ['id' => (string) Str::uuid(), 'label' => 'Stuck', 'color' => '#e2445c', 'is_active' => true],
            ],
        ];
    }
}
