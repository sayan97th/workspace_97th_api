<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardAutomationRequest;
use App\Http\Requests\Board\UpdateBoardAutomationRequest;
use App\Http\Resources\BoardAutomationResource;
use App\Models\BoardAutomation;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardAutomationController extends Controller
{
    public function __construct(private readonly BoardViewResolver $view_resolver) {}

    /**
     * GET /api/boards/{item}/automations
     *
     * Scoped to one tab — `view_id` if given, otherwise the board's primary
     * tab, mirroring `BoardColumnController::index()`.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view_id = $request->filled('view_id') ? (int) $request->query('view_id') : null;
        $view = $this->view_resolver->resolveForRead($item, $view_id);

        if (! $view) {
            return response()->json(['data' => []]);
        }

        $automations = BoardAutomation::where('board_view_id', $view->id)->orderByDesc('id')->get();

        return response()->json([
            'data' => BoardAutomationResource::collection($automations),
        ]);
    }

    /**
     * POST /api/boards/{item}/automations
     */
    public function store(StoreBoardAutomationRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $automation = BoardAutomation::create([
            'board_id' => $item->id,
            'board_view_id' => $validated['view_id'],
            'name' => $validated['name'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'trigger_type' => $validated['trigger_type'],
            'trigger_column_id' => $validated['trigger_column_id'],
            'trigger_value' => $validated['trigger_value'] ?? null,
            'action_type' => $validated['action_type'],
            'action_params' => $validated['action_params'] ?? [],
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Automation created successfully.',
            'automation' => new BoardAutomationResource($automation),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/automations/{automation}
     *
     * Toggling `is_enabled` (the automations list's own on/off switch) is the
     * most common call here; the rest of the recipe is otherwise edited in
     * place too, rather than requiring a delete-and-recreate.
     */
    public function update(UpdateBoardAutomationRequest $request, WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $automation->fill($request->validated())->save();

        return response()->json([
            'message' => 'Automation updated successfully.',
            'automation' => new BoardAutomationResource($automation->fresh()),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/automations/{automation}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $automation->delete();

        return response()->json([
            'message' => 'Automation deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the automation is not part of the board.
     */
    private function ensureAutomationBelongsToBoard(WorkspaceNavigationItem $item, BoardAutomation $automation): void
    {
        abort_if($automation->board_id !== $item->id, 404);
    }
}
