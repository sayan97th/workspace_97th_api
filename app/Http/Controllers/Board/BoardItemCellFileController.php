<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardItemCellFileLinkRequest;
use App\Http\Requests\Board\StoreBoardItemCellFileRequest;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ColumnPermissionService;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to one Files-type column's cell, distinct from
 * {@see BoardItemAttachmentController}, which attaches a file to the item as
 * a whole. A Files cell's value *is* the file list (an array of
 * `{id, kind, file_name, url, mime_type, size_bytes}`), stored directly in
 * `board_item_values` exactly like any other column's value. There is no
 * separate table for it, so every read of the current list goes through the
 * item's own `values` relation rather than a dedicated model.
 *
 * Each entry is either an uploaded file (`kind: file`, with a storage `path`)
 * or an external link (`kind: link`, no `path`, `size_bytes: 0`). Entries
 * saved before `kind` existed have none and are treated as uploaded files.
 */
class BoardItemCellFileController extends Controller
{
    public const KIND_FILE = 'file';

    public const KIND_LINK = 'link';

    /**
     * POST /api/boards/{item}/items/{board_item}/columns/{column}/files
     *
     * Appends one or more freshly uploaded files onto the column's existing
     * list for this item.
     */
    public function store(StoreBoardItemCellFileRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column): JsonResponse
    {
        $this->authorizeCellEdit($request, $item, $board_item, $column);

        $uploaded = collect($request->file('files', []))->map(function ($file) use ($board_item) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                "board-cell-files/{$board_item->id}",
                Str::uuid().'.'.$extension,
                config('filesystems.app_disk')
            );

            return [
                'id' => (string) Str::uuid(),
                'kind' => self::KIND_FILE,
                'file_name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk(config('filesystems.app_disk'))->url($path),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ];
        });

        $next_files = $this->currentFiles($board_item, $column)->concat($uploaded)->values();
        $this->saveFiles($board_item, $column, $next_files);

        return response()->json([
            'message' => 'File uploaded successfully.',
            'files' => $next_files,
        ], 201);
    }

    /**
     * POST /api/boards/{item}/items/{board_item}/columns/{column}/files/link
     *
     * Appends an external link (the cell menu's "From Link") onto the
     * column's existing list for this item. Nothing is downloaded or stored,
     * the entry simply opens `url` in a new tab.
     */
    public function storeLink(StoreBoardItemCellFileLinkRequest $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column): JsonResponse
    {
        $this->authorizeCellEdit($request, $item, $board_item, $column);

        $url = $request->validated('url');
        $text = $request->validated('text');

        $link = [
            'id' => (string) Str::uuid(),
            'kind' => self::KIND_LINK,
            'file_name' => $text !== null && $text !== '' ? $text : $url,
            'path' => null,
            'url' => $url,
            'mime_type' => 'text/uri-list',
            'size_bytes' => 0,
        ];

        $next_files = $this->currentFiles($board_item, $column)->push($link)->values();
        $this->saveFiles($board_item, $column, $next_files);

        return response()->json([
            'message' => 'Link added successfully.',
            'files' => $next_files,
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/items/{board_item}/columns/{column}/files/{file_id}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column, string $file_id): JsonResponse
    {
        $this->authorizeCellEdit($request, $item, $board_item, $column);

        $existing = $this->currentFiles($board_item, $column);
        $target = $existing->firstWhere('id', $file_id);

        // A link entry has no stored file. The empty path check matters, an
        // `exists('')` on the local disk would match the disk root itself.
        $target_path = is_array($target) ? (string) ($target['path'] ?? '') : '';
        if ($target_path !== '' && Storage::disk(config('filesystems.app_disk'))->exists($target_path)) {
            Storage::disk(config('filesystems.app_disk'))->delete($target_path);
        }

        $next_files = $existing->reject(fn (array $file) => ($file['id'] ?? null) === $file_id)->values();
        $this->saveFiles($board_item, $column, $next_files);

        return response()->json([
            'message' => 'File removed successfully.',
            'files' => $next_files,
        ]);
    }

    /**
     * Shared guard for every write: the item and column belong to this board,
     * the column is a Files column, and the viewer may edit both the item and
     * that specific column.
     */
    private function authorizeCellEdit(Request $request, WorkspaceNavigationItem $item, BoardItem $board_item, BoardColumn $column): void
    {
        $this->ensureItemBelongsToBoard($item, $board_item);
        $this->ensureColumnIsFilesType($item, $column);
        BoardEditGate::authorizeItem($item, $request->user(), $board_item);
        app(ColumnPermissionService::class)->authorizeEdit($item, $request->user(), [$column->id]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $files
     */
    private function saveFiles(BoardItem $board_item, BoardColumn $column, Collection $files): void
    {
        $board_item->values()->updateOrCreate(
            ['column_id' => $column->id],
            ['value' => $files->all()]
        );
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
