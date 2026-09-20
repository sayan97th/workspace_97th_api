<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardAutomationRequest;
use App\Http\Requests\Board\UpdateBoardAutomationRequest;
use App\Http\Resources\BoardAutomationResource;
use App\Http\Resources\BoardAutomationRunLogResource;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BoardAutomationController extends Controller
{
    private const RUNS_DEFAULT_PER_PAGE = 25;

    private const RUNS_MAX_PER_PAGE = 100;

    private const USAGE_DAYS = 30;

    public function __construct(private readonly BoardViewResolver $view_resolver) {}

    /**
     * GET /api/boards/{item}/automations
     *
     * Scoped to one tab — `view_id` if given, otherwise the board's primary
     * tab, mirroring `BoardColumnController::index()`.
     */
    public function index(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view_id = $request->filled('view_id') ? (int) $request->query('view_id') : null;
        $view = $this->view_resolver->resolveForRead($item, $view_id);

        if (! $view) {
            return response()->json(['data' => []]);
        }

        $automations = BoardAutomation::where('board_view_id', $view->id)
            ->with('creator')
            ->withCount('runLogs')
            ->withMax('runLogs', 'created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => BoardAutomationResource::collection($automations),
        ]);
    }

    /**
     * POST /api/boards/{item}/automations
     */
    public function store(StoreBoardAutomationRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();

        $automation = BoardAutomation::create([
            'board_id' => $item->id,
            'board_view_id' => $validated['view_id'],
            'name' => $validated['name'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'trigger_type' => $validated['trigger_type'],
            'trigger_column_id' => $validated['trigger_column_id'] ?? null,
            'trigger_value' => $validated['trigger_value'] ?? null,
            'action_type' => $validated['action_type'],
            'action_params' => $validated['action_params'] ?? [],
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Automation created successfully.',
            'automation' => new BoardAutomationResource($this->withRunStats($automation)),
        ], 201);
    }

    /**
     * PATCH /api/boards/{item}/automations/{automation}
     *
     * Toggling `is_enabled` (the automations list's own on/off switch) is the
     * most common call here; the rest of the recipe is otherwise edited in
     * place too, rather than requiring a delete-and-recreate.
     */
    public function update(UpdateBoardAutomationRequest $request, WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $automation->fill($request->validated())->save();

        return response()->json([
            'message' => 'Automation updated successfully.',
            'automation' => new BoardAutomationResource($this->withRunStats($automation->fresh())),
        ]);
    }

    /**
     * POST /api/boards/{item}/automations/{automation}/duplicate
     *
     * The copy starts switched off, so a rule that reacts to the same event twice is never
     * live by accident. The user turns it on once they have adjusted it.
     */
    public function duplicate(Request $request, WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $copy = $automation->replicate();
        $copy->name = $automation->name ? Str::limit($automation->name, 245, '').' (copy)' : null;
        $copy->is_enabled = false;
        $copy->created_by_id = $request->user()?->id;
        $copy->save();

        return response()->json([
            'message' => 'Automation duplicated successfully.',
            'automation' => new BoardAutomationResource($this->withRunStats($copy)),
        ], 201);
    }

    /**
     * GET /api/boards/{item}/automations/runs
     *
     * The Manage tab's "Run history", newest first. Scoped to one tab like `index()`, and
     * narrowed by `status`, `automation_id` and a `from`/`to` date range.
     */
    public function runs(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $filters = $request->validate([
            'view_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(BoardAutomationRunLog::statuses())],
            'automation_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::RUNS_MAX_PER_PAGE],
        ]);

        $view = $this->view_resolver->resolveForRead($item, isset($filters['view_id']) ? (int) $filters['view_id'] : null);

        $per_page = (int) ($filters['per_page'] ?? self::RUNS_DEFAULT_PER_PAGE);

        if (! $view) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $per_page, 'total' => 0]]);
        }

        $page = BoardAutomationRunLog::query()
            ->where('board_view_id', $view->id)
            ->with('actor')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['automation_id'] ?? null, fn ($query, $automation_id) => $query->where('automation_id', $automation_id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($per_page);

        return response()->json([
            'data' => BoardAutomationRunLogResource::collection($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/boards/{item}/automations/usage
     *
     * The Manage tab's usage numbers for one tab: how many times its automations ran over the
     * last {@see self::USAGE_DAYS} days, split by outcome, by day, by automation and by action.
     */
    public function usage(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view_id = $request->filled('view_id') ? (int) $request->query('view_id') : null;
        $view = $this->view_resolver->resolveForRead($item, $view_id);

        $since = Carbon::today()->subDays(self::USAGE_DAYS - 1);
        $empty_days = collect(range(0, self::USAGE_DAYS - 1))
            ->mapWithKeys(fn (int $offset) => [$since->copy()->addDays($offset)->toDateString() => 0]);

        if (! $view) {
            return response()->json(['data' => $this->usagePayload(0, 0, 0, 0, 0, 0, $empty_days->all(), [], [])]);
        }

        $window = BoardAutomationRunLog::query()->where('board_view_id', $view->id)->where('created_at', '>=', $since);

        $by_status = (clone $window)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $by_day = (clone $window)->selectRaw('DATE(created_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');
        $daily = $empty_days->map(fn (int $count, string $day) => (int) ($by_day[$day] ?? 0))->all();

        $top_automations = (clone $window)
            ->selectRaw('automation_id, max(automation_name) as automation_name, max(trigger_type) as trigger_type, max(action_type) as action_type, count(*) as total')
            ->groupBy('automation_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'automation_id' => $row->automation_id,
                'automation_name' => $row->automation_name,
                'trigger_type' => $row->trigger_type,
                'action_type' => $row->action_type,
                'runs' => (int) $row->total,
            ])
            ->all();

        $by_action = (clone $window)
            ->selectRaw('action_type, count(*) as total')
            ->groupBy('action_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['action_type' => $row->action_type, 'runs' => (int) $row->total])
            ->all();

        $automations = BoardAutomation::where('board_view_id', $view->id);

        return response()->json(['data' => $this->usagePayload(
            (int) $by_status->sum(),
            (int) ($by_status[BoardAutomationRunLog::STATUS_SUCCESS] ?? 0),
            (int) ($by_status[BoardAutomationRunLog::STATUS_FAILED] ?? 0),
            (int) ($by_status[BoardAutomationRunLog::STATUS_SKIPPED] ?? 0),
            (clone $automations)->count(),
            (clone $automations)->where('is_enabled', true)->count(),
            $daily,
            $top_automations,
            $by_action,
        )]);
    }

    /**
     * @param  array<string, int>  $daily
     * @param  array<int, array<string, mixed>>  $top_automations
     * @param  array<int, array<string, mixed>>  $by_action
     * @return array<string, mixed>
     */
    private function usagePayload(int $runs, int $success, int $failed, int $skipped, int $automations, int $enabled, array $daily, array $top_automations, array $by_action): array
    {
        return [
            'period_days' => self::USAGE_DAYS,
            'runs' => $runs,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'automations' => $automations,
            'enabled_automations' => $enabled,
            'daily' => collect($daily)->map(fn (int $count, string $day) => ['date' => $day, 'runs' => $count])->values()->all(),
            'top_automations' => $top_automations,
            'by_action' => $by_action,
        ];
    }

    /**
     * Adds what the Manage tab shows next to an automation, its run count, last run and creator.
     */
    private function withRunStats(BoardAutomation $automation): BoardAutomation
    {
        return $automation->load('creator')->loadCount('runLogs')->loadMax('runLogs', 'created_at');
    }

    /**
     * DELETE /api/boards/{item}/automations/{automation}
     */
    public function destroy(WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $automation->delete();

        return response()->json([
            'message' => 'Automation deleted successfully.',
        ]);
    }

    /**
     * Guard: abort with 404 when the automation is not part of the board.
     */
    private function ensureAutomationBelongsToBoard(WorkspaceNavigationItem $item, BoardAutomation $automation): void
    {
        abort_if($automation->board_id !== $item->id, 404);
    }
}
