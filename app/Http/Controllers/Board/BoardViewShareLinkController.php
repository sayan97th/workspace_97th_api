<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\UpdateBoardViewShareLinkRequest;
use App\Models\BoardView;
use App\Models\BoardViewShareLink;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\SharedViewService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A board view's "Share view" link: a read only public copy of the view for
 * people outside the account. Only board owners manage it.
 */
class BoardViewShareLinkController extends Controller
{
    /**
     * GET /api/boards/{item}/views/{board_view}/share-link
     */
    public function show(WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureShareableView($item, $board_view);

        return response()->json(['link' => $this->present($board_view->shareLink)]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/share-link
     *
     * Creates the link, or turns an existing one back on.
     */
    public function store(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureShareableView($item, $board_view);
        $this->authorizeOwner($item, $request);

        $link = $board_view->shareLink ?? new BoardViewShareLink([
            'board_id' => $item->id,
            'board_view_id' => $board_view->id,
            'token' => BoardViewShareLink::generateToken(),
            'created_by_id' => $request->user()->id,
        ]);
        $link->is_enabled = true;
        $link->save();

        return response()->json(['message' => 'Share link created.', 'link' => $this->present($link)], 201);
    }

    /**
     * PATCH /api/boards/{item}/views/{board_view}/share-link
     */
    public function update(UpdateBoardViewShareLinkRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureShareableView($item, $board_view);
        $this->authorizeOwner($item, $request);

        $link = $board_view->shareLink;
        abort_if($link === null, 404, 'This view has no share link yet.');

        $link->fill($request->validated())->save();

        return response()->json(['message' => 'Share link updated.', 'link' => $this->present($link->fresh())]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/share-link/regenerate
     *
     * Replaces the token, so the old link stops working right away.
     */
    public function regenerate(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureShareableView($item, $board_view);
        $this->authorizeOwner($item, $request);

        $link = $board_view->shareLink;
        abort_if($link === null, 404, 'This view has no share link yet.');

        $link->update(['token' => BoardViewShareLink::generateToken()]);

        return response()->json(['message' => 'A new share link was created.', 'link' => $this->present($link->fresh())]);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}/share-link
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureShareableView($item, $board_view);
        $this->authorizeOwner($item, $request);

        $board_view->shareLink?->delete();

        return response()->json(['message' => 'Share link removed.']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?BoardViewShareLink $link): ?array
    {
        return $link ? [
            'token' => $link->token,
            'is_enabled' => $link->is_enabled,
            'has_password' => $link->password !== null,
            'last_accessed_at' => $link->last_accessed_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ] : null;
    }

    private function ensureShareableView(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
        abort_unless(in_array($board_view->view_type, SharedViewService::SHAREABLE_VIEW_TYPES, true), 422, 'Only views with items can be shared.');
    }

    private function authorizeOwner(WorkspaceNavigationItem $item, Request $request): void
    {
        if (! BoardEditGate::isOwner($item, $request->user())) {
            throw ValidationException::withMessages([
                'board' => 'Only board owners can share this view.',
            ])->status(403);
        }
    }
}
