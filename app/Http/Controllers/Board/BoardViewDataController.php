<?php

namespace App\Http\Controllers\Board;

use App\Enums\BoardViewType;
use App\Http\Controllers\Controller;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\DashboardDataService;
use App\Services\Board\WorkloadDataService;
use App\Support\BoardVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Computed data for the tabs that show another tab's items instead of
 * holding their own: Workload and Dashboard. Their settings are saved
 * through the regular view update (`workload_config`, `dashboard_config`).
 */
class BoardViewDataController extends Controller
{
    /** Boards listed by the Dashboard widget's board picker. */
    private const SOURCE_BOARD_LIMIT = 50;

    public function __construct(
        private readonly WorkloadDataService $workload_service,
        private readonly DashboardDataService $dashboard_service,
    ) {}

    /**
     * GET /api/boards/{item}/views/{board_view}/workload-data?start=YYYY-MM-DD
     */
    public function workload(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewOfType($item, $board_view, BoardViewType::Workload);
        $validated = $request->validate(['start' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json($this->workload_service->build($item, $board_view, $validated['start'] ?? null));
    }

    /**
     * GET /api/boards/{item}/views/{board_view}/dashboard-data
     */
    public function dashboard(Request $request, WorkspaceNavigationItem $item, BoardView $board_view): JsonResponse
    {
        $this->ensureViewOfType($item, $board_view, BoardViewType::Dashboard);

        return response()->json($this->dashboard_service->build($item, $board_view, $request->user()));
    }

    /**
     * GET /api/boards/{item}/dashboard-sources?search=
     *
     * The boards a Dashboard widget may read, the ones the viewer can open.
     * The dashboard's own board is always listed first.
     */
    public function sources(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));

        $boards = BoardVisibility::query($request->user())
            ->where('id', '!=', $item->id)
            ->where(fn ($query) => $query->whereNull('view_key')->orWhereNotIn('view_key', ['doc']))
            ->when($search !== '', fn ($query) => $query->where('label', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->with('workspace:id,name')
            ->orderBy('label')
            ->limit(self::SOURCE_BOARD_LIMIT)
            ->get(['id', 'label', 'workspace_id']);

        $present = fn (WorkspaceNavigationItem $board) => [
            'id' => $board->id,
            'label' => $board->label,
            'workspace_name' => $board->workspace?->name,
        ];

        return response()->json([
            'boards' => collect([$item->loadMissing('workspace:id,name')])->concat($boards)->map($present)->values(),
        ]);
    }

    private function ensureViewOfType(WorkspaceNavigationItem $item, BoardView $board_view, BoardViewType $type): void
    {
        abort_if($board_view->board_id !== $item->id, 404);
        abort_if($board_view->view_type !== $type->value, 422, 'This view has no '.$type->value.' data.');
    }
}
