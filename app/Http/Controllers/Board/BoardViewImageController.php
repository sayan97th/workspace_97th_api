<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewImageRequest;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BoardViewImageController extends Controller
{
    /**
     * POST /api/boards/{item}/views/{board_view}/images
     *
     * Stores an image embedded into a `doc` view's markdown body (via the
     * `BoardDocEditor`'s image-upload plugin) on the `public` disk, the same
     * `Str::uuid()`-filename convention as `ProfilePhotoController` and
     * `BoardItemCommentController`. Stateless by design — the resulting URL
     * is embedded directly into the view's `doc_content` markdown by the
     * client (saved through the existing `BoardViewController::update`), so
     * no attachment row is needed here.
     */
    public function store(StoreBoardViewImageRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $file = $request->file('image');
        $extension = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            "board-doc-images/{$board_view->id}",
            Str::uuid().'.'.$extension,
            'public'
        );

        return response()->json([
            'message' => 'Image uploaded successfully.',
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    /**
     * Guard: abort with 404 when the view is not part of the board.
     */
    private function ensureViewBelongsToBoard(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
    }
}
