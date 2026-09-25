<?php

namespace App\Http\Controllers\Board;

use App\Enums\BoardViewType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicAccess\PublicFormController;
use App\Http\Requests\Board\UpdateBoardFormRequest;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardFormService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Form view builder inside a board. The public side (anonymous
 * respondents) lives in {@see PublicFormController}.
 */
class BoardFormController extends Controller
{
    public function __construct(private readonly BoardFormService $forms) {}

    /**
     * GET /api/boards/{item}/views/{board_view}/form
     */
    public function show(WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureFormView($item, $board_view);

        return response()->json($this->forms->builder($item, $board_view));
    }

    /**
     * PATCH /api/boards/{item}/views/{board_view}/form
     */
    public function update(UpdateBoardFormRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureFormView($item, $board_view);
        BoardEditGate::authorizeStructure($item, $request->user());

        $this->forms->update($item, $board_view, $request->validated());

        return response()->json([
            'message' => 'Form saved successfully.',
            ...$this->forms->builder($item, $board_view->fresh()),
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/form/regenerate-link
     *
     * Replaces the public link, so every copy shared so far stops working.
     */
    public function regenerateLink(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureFormView($item, $board_view);
        BoardEditGate::authorizeStructure($item, $request->user());

        return response()->json([
            'message' => 'A new form link was created.',
            'token' => $this->forms->regenerateToken($board_view),
        ]);
    }

    private function ensureFormView(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
        abort_if($board_view->view_type !== BoardViewType::Form->value, 422, 'This view is not a form.');
    }
}
