<?php

namespace App\Http\Controllers\Board;

use App\Exports\BoardItemUpdatesExport;
use App\Http\Controllers\Controller;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoardItemUpdatesExportController extends Controller
{
    /**
     * GET /api/boards/{item}/items/{board_item}/updates/export
     *
     * Item drawer's "..." menu > "Export updates to Excel", downloads every
     * published update on the item, with its replies, as an .xlsx workbook.
     */
    public function export(WorkspaceNavigationItem $item, BoardItem $board_item): BinaryFileResponse
    {
        abort_if($board_item->board_id !== $item->id, 404);

        $updates = $board_item->comments()
            ->whereNull('parent_id')
            ->visibleNow()
            ->with(['author', 'likes', 'attachments', 'replies.author', 'replies.likes', 'replies.attachments'])
            ->orderBy('created_at')
            ->get();

        $filename = (Str::slug($board_item->name, '_') ?: 'item').'_updates.xlsx';

        return Excel::download(new BoardItemUpdatesExport($board_item->name, $updates), $filename);
    }
}
