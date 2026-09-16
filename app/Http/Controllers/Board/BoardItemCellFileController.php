<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemCellFileRequest;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to one Files-type column's cell — distinct from
 * {@see BoardItemAttachmentController}, which attaches a file to the item as
 * a whole. A Files cell's value *is* the file list (an array of
 * `{id, file_name, url, mime_type, size_bytes}`), stored directly in
 * `board_item_values` exactly like any other column's value — there is no
 * separate table for it, so every read of the current list goes through the
 * item's own `values` relation rather than a dedicated model.
 */
class BoardItemCellFileController extends Controller
{
    /**
     * POST /api/boards/{item}/items/{board_item}/columns/{column}/files
     *
     * Appends one or more freshly uploaded files onto the column's existing
     * list for this item.
     */
    public function store(StoreBoardItemCellFileRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureColumnIsFilesType($item, $column);

        $uploaded = collect($request->file('files', []))->map(function ($file) use ($board_item) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-cell-files/{$board_item->id}",
                Str::uuid().'.'.$extension,
                config('filesystems.app_disk')
            );

            return [
                'id' => (string) Str::uuid(),
                'file_name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk(config('filesystems.app_disk'))->url($path),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ];
        });

        $next_files = $this->currentFiles($board_item, $column)->concat($uploaded)->values();

        $board_item->values()->updateOrCreate(
            ['column_id' => $column->id],
            ['value' => $next_files->all()]
        );

        return response()->json([
            'message' => 'File uploaded successfully.',
            'files' => $next_files->values(),
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/columns/{column}/files/{file_id}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column, string $file_id): JsonResponse
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureColumnIsFilesType($item, $column);

        $existing = $this->currentFiles($board_item, $column);
        $target = $existing->firstWhere('id', $file_id);

        if ($target && Storage::disk(config('filesystems.app_disk'))->exists($target['path'] ?? '')) {
            Storage::disk(config('filesystems.app_disk'))->delete($target['path']);
        }

        $next_files = $existing->reject(fn (array $file) => ($file['id'] ?? null) === $file_id)->values();

        $board_item->values()->updateOrCreate(
            ['column_id' => $column->id],
            ['value' => $next_files->all()]
        );

        return response()->json([
            'message' => 'File removed successfully.',
            'files' => $next_files,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function currentFiles(BoardItem $board_item, BoardColumn $column): Collection
    {
        $value = $board_item->values()->where('column_id', $column->id)->first()?->value;

        return collect(is_array($value) ? $value : []);
    }

    /**
     * Guard: abort with 404 when the item is not part of the board.
     */
    private function ensureItemBelongsToBoard(WorkspaceNavigationItem $item, BoardItem $board_item): void
    {
        abort_if($board_item->board_id !== $item->id, 404);
    }

    /**
     * Guard: abort with 404 when the column isn't a Files column on this board.
     */
    private function ensureColumnIsFilesType(WorkspaceNavigationItem $item, BoardColumn $column): void
    {
        abort_if($column->board_id !== $item->id || $column->type !== BoardColumn::TYPE_FILES, 404);
    }
}
