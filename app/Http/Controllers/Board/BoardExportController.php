<?php

namespace App\Http\Controllers\Board;

use App\Exports\BoardItemsExport;
use App\Http\Controllers\Controller;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoardExportController extends Controller
{
    public function __construct(private readonly BoardViewResolver $view_resolver) {}

    /**
     * GET /api/boards/{item}/export
     *
     * Board options menu's "More actions" > "Export board to Excel" —
     * downloads the tab's (`view_id`, defaulting to the primary tab)
     * top-level items as an .xlsx workbook, one column per item-scoped
     * board column. With `group_id` (the group menu's "Export to Excel")
     * only that group's items are exported and the file is named after it.
     */
    public function export(Request $request, WorkspaceNavigationItem $item): BinaryFileResponse
    {
        $view_id = $request->filled('view_id') ? (int) $request->query('view_id') : null;
        $view = $this->view_resolver->resolveForRead($item, $view_id);
        $group = $request->filled('group_id')
            ? $item->groups()->where('is_archived', false)->findOrFail((int) $request->query('group_id'))
            : null;
        if ($group !== null && $group->board_view_id !== null) {
            $view = $item->views()->find($group->board_view_id) ?? $view;
        }

        $columns = $view
            ? $view->columns()->where('scope', BoardColumn::SCOPE_ITEM)->orderBy('position')->get()
            : collect();

        $items = $view
            ? $item->items()
                ->where('is_archived', false)
                ->whereNull('parent_id')
                ->whereHas('group', fn ($query) => $query->where('board_view_id', $view->id)->where('is_archived', false))
                ->when($group !== null, fn ($query) => $query->where('group_id', $group->id))
                ->with('values')
                ->orderBy('group_id')->orderBy('position')
                ->get()
            : collect();

        $people_by_id = $item->workspace->users()->get()->keyBy('id');

        $sheet_title = $group !== null ? $group->name : $item->label;
        $export = new BoardItemsExport($sheet_title, $items, $columns, $people_by_id);
        $file_label = $group !== null ? "{$item->label} {$group->name}" : $item->label;
        $filename = Str::slug($file_label, '_').'_export.xlsx';

        return Excel::download($export, $filename);
    }
}
