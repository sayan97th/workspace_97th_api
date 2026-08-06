<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardViewFileRequest;
use App\Http\Resources\BoardViewFileResource;
use App\Models\BoardView;
use App\Models\BoardViewFile;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BoardViewFileController extends Controller
{
    /**
     * GET /api/boards/{item}/views/{board_view}/files
     *
     * Every file uploaded to this Files Gallery tab, newest first.
     */
    public function index(WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        return response()->json([
            'data' => BoardViewFileResource::collection($board_view->files()->with('uploader')->get()),
        ]);
    }

    /**
     * POST /api/boards/{item}/views/{board_view}/files
     *
     * Uploads one or more files (the dropzone's multi-file drop/picker) in a
     * single multipart request, mirroring how `BoardItemCommentController`
     * stores comment attachments.
     */
    public function store(StoreBoardViewFileRequest $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);

        $files = collect($request->file('files', []))->map(function ($file) use ($request, $board_view) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-view-files/{$board_view->id}",
                Str::uuid().'.'.$extension,
                'public'
            );

            $created = $board_view->files()->create([
                'uploaded_by_id' => $request->user()?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'extension' => $extension,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ]);

            // Every file in this batch shares the same uploader (the current
            // user) — set the relation directly instead of a redundant query.
            return $created->setRelation('uploader', $request->user());
        });

        return response()->json([
            'message' => 'Files uploaded successfully.',
            'data' => BoardViewFileResource::collection($files),
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/views/{board_view}/files/{file}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardView $board_view, BoardViewFile $file): JsonResponse
    {
        $this->ensureViewBelongsToBoard($item, $board_view);
        $this->ensureFileBelongsToView($board_view, $file);

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the view is not part of the board.
     */
    private function ensureViewBelongsToBoard(WorkspaceNavigationItem $item, BoardView $board_view): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 404 when the file is not on this view.
     */
    private function ensureFileBelongsToView(BoardView $board_view, BoardViewFile $file): void
    {
        abort_if($file->board_view_id !== $board_view->id, 404);
    }
}
