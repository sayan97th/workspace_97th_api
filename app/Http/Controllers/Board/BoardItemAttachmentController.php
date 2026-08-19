<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemAttachmentRequest;
use App\Http\Resources\BoardItemAttachmentResource;
use App\Models\BoardItem;
use App\Models\BoardItemAttachment;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached directly to a Kanban card (the drawer's "Attachments"
 * affordance), independent of comments — see {@see BoardItemAttachment}.
 */
class BoardItemAttachmentController extends Controller
{
    /**
     * GET /api/boards/{item}/items/{board_item}/attachments
     */
    public function index(WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        return response()->json([
            'data' => BoardItemAttachmentResource::collection(
                $board_item->attachments()->orderByDesc('created_at')->get()
            ),
        ]);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/attachments
     *
     * Stores one or more files straight onto the item — unlike
     * `BoardItemCommentController::store()`'s `attachments` field, this
     * never creates a comment.
     */
    public function store(StoreBoardItemAttachmentRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);

        $created = collect($request->file('files', []))->map(function ($file) use ($request, $board_item) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-item-attachments/{$board_item->id}",
                Str::uuid().'.'.$extension,
                'public'
            );

            return $board_item->attachments()->create([
                'uploaded_by_id' => $request->user()?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'extension' => $extension,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ]);
        });

        return response()->json([
            'message' => 'Attachment uploaded successfully.',
            'data' => BoardItemAttachmentResource::collection($created),
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/attachments/{attachment}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardItemAttachment $attachment): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureAttachmentBelongsToItem($board_item, $attachment);

        if (Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }
        $attachment->delete();

        return response()->json([
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the item is not part of the board.
     */
    private function ensureItemBelongsToBoard(WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 404 when the attachment is not on this item.
     */
    private function ensureAttachmentBelongsToItem(BoardItem $board_item, BoardItemAttachment $attachment): void
    {
        abort_if($attachment->item_id !== $board_item->id, 404);
    }
}
