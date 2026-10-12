<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\BoardController;
use App\Http\Requests\Board\StoreBoardDocFileRequest;
use App\Http\Requests\Board\UpdateBoardDocRequest;
use App\Http\Resources\BoardDocResource;
use App\Models\BoardDoc;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The body of a Doc (a navigation leaf with `view_key = doc`), edited in the
 * Notion style editor on the frontend. Reading follows the board itself
 * ({@see BoardController::show()}), writing
 * and uploading need content edit access ({@see BoardEditGate}).
 */
class BoardDocController extends Controller
{
    /**
     * GET /api/boards/{item}/doc
     *
     * Returns an empty doc (null content) when nothing was saved yet.
     */
    public function show(WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureLeaf($item);

        $doc = $item->doc()->with('updatedBy')->first() ?? new BoardDoc(['board_id' => $item->id]);

        return response()->json(new BoardDocResource($doc));
    }

    /**
     * PUT /api/boards/{item}/doc
     *
     * Replaces the whole document, called by the editor's debounced autosave.
     */
    public function update(UpdateBoardDocRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureLeaf($item);
        BoardEditGate::authorizeContent($item, $request->user());

        $doc = BoardDoc::updateOrCreate(
            ['board_id' => $item->id],
            [
                'content' => $request->validated('content'),
                'updated_by_id' => $request->user()->id,
            ],
        );

        // Keeps the board's own "Last updated" in step with its body.
        $item->touch();

        return response()->json([
            'message' => 'Doc saved successfully.',
            'doc' => new BoardDocResource($doc->load('updatedBy')),
        ]);
    }

    /**
     * POST /api/boards/{item}/doc/files
     *
     * Stores an image, video, audio clip or attachment embedded in the doc on
     * the app disk. Stateless like {@see BoardViewImageController::store()}: the
     * returned URL lives inside the doc content, so no attachment row is kept.
     */
    public function storeFile(StoreBoardDocFileRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->ensureLeaf($item);
        BoardEditGate::authorizeContent($item, $request->user());

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $disk = config('filesystems.app_disk');
        $path = $file->storeAs("board-docs/{$item->id}", Str::uuid().'.'.$extension, $disk);

        return response()->json([
            'message' => 'File uploaded successfully.',
            'url' => Storage::disk($disk)->url($path),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
        ], 201);
    }

    /**
     * Guard: folders have no body.
     */
    private function ensureLeaf(WorkspaceNavigationItem $item): void
    {
        abort_if($item->type !== WorkspaceNavigationItem::TYPE_LEAF, 404);
    }
}
