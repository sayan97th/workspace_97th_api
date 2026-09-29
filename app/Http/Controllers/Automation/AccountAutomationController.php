<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardAutomationResource;
use App\Models\AccountSetting;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\AutomationUsageMeter;
use App\Services\Board\BoardAutomationHealthChecker;
use App\Support\BoardEditGate;
use App\Support\VisibleBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The account wide Automations center (`/automations` in the app): every automation on every board
 * the user may open, with its board, health and recent runs, and bulk on and off across boards.
 * Changing one still needs edit rights on its board, see {@see BoardEditGate}. Everything else
 * about one automation stays on its board's own Automations center.
 */
class AccountAutomationController extends Controller
{
    /** Most automations the center lists, newest first. */
    private const MAX_AUTOMATIONS = 1000;

    private const MAX_BULK = 200;

    /** The window of the "runs" and "failed" counts. */
    private const RECENT_DAYS = 30;

    public function __construct(
        private readonly BoardAutomationHealthChecker $health_checker,
        private readonly AutomationUsageMeter $usage_meter,
    ) {}

    /**
     * GET /api/automations/usage
     *
     * This month's automation actions against the account's monthly limit, with the boards the
     * user may open that used the most. Anyone may read it, only admins may change the limit.
     */
    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->usage_meter->summary(VisibleBoards::query($user)->pluck('id'), $this->canManageUsage($user)),
        ]);
    }

    /**
     * PUT /api/automations/usage
     *
     * Sets the monthly action limit, null for no limit. Admins only.
     */
    public function updateUsage(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canManageUsage($user), 403, 'Only account admins can change the automation limit.');

        $validated = $request->validate([
            'monthly_action_limit' => ['present', 'nullable', 'integer', 'between:1,'.AutomationUsageMeter::MAX_LIMIT],
        ]);

        AccountSetting::current()->update(['automation_monthly_action_limit' => $validated['monthly_action_limit']]);

        return response()->json([
            'message' => $validated['monthly_action_limit'] === null ? 'Automations now have no monthly limit.' : 'The monthly automation limit was saved.',
            'data' => $this->usage_meter->summary(VisibleBoards::query($user)->pluck('id'), true),
        ]);
    }

    private function canManageUsage(User $user): bool
    {
        return $user->hasRole(['super_admin', 'admin']);
    }

    /**
     * GET /api/automations
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $boards = VisibleBoards::query($user)->with('workspace:id,name')->get()->keyBy('id');

        $automations = BoardAutomation::query()
            ->whereIn('board_id', $boards->keys())
            ->with(['creator', 'owner', 'boardView:id,label'])
            ->withCount('runLogs')
            ->withMax('runLogs', 'created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_AUTOMATIONS + 1)
            ->get();
        $is_truncated = $automations->count() > self::MAX_AUTOMATIONS;
        $automations = $automations->take(self::MAX_AUTOMATIONS);

        $recent = $this->recentRuns($automations->pluck('id'));
        $can_edit = $boards->map(fn (WorkspaceNavigationItem $board) => BoardEditGate::allows($board, $user));

        $rows = $automations->map(function (BoardAutomation $automation) use ($request, $boards, $recent, $can_edit) {
            $board = $boards->get($automation->board_id);
            $stats = $recent->get($automation->id);

            return [
                ...(new BoardAutomationResource($automation))->toArray($request),
                'board' => [
                    'id' => $automation->board_id,
                    'label' => $board?->label,
                    'workspace_name' => $board?->workspace?->name,
                ],
                'view_label' => $automation->boardView?->label,
                'can_edit' => (bool) ($can_edit->get($automation->board_id) ?? false),
                'recent_runs' => (int) ($stats->runs ?? 0),
                'recent_failures' => (int) ($stats->failed ?? 0),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'enabled' => $rows->where('is_enabled', true)->count(),
                'paused' => $rows->filter(fn (array $row) => ! $row['is_enabled'] && $row['paused_at'] !== null)->count(),
                'failing' => $rows->filter(fn (array $row) => $row['consecutive_failures'] > 0)->count(),
                'broken' => $rows->filter(fn (array $row) => $row['problems'] !== [])->count(),
                'boards' => $rows->pluck('board.id')->unique()->count(),
                'recent_runs' => $rows->sum('recent_runs'),
                'recent_days' => self::RECENT_DAYS,
            ],
            'is_truncated' => $is_truncated,
        ]);
    }

    /**
     * POST /api/automations/bulk
     *
     * Turns automations of any boards on or off. Automations on a board the user may not edit, or
     * that use something deleted (when turning on), are left alone and listed under `skipped`.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'automation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'automation_ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['enable', 'disable'])],
        ]);

        $user = $request->user();
        $visible_board_ids = VisibleBoards::query($user)->pluck('id')->all();
        $automations = BoardAutomation::whereIn('id', $validated['automation_ids'])->whereIn('board_id', $visible_board_ids)->with('board')->get();
        $is_enabled = $validated['action'] === 'enable';
        $affected = [];
        $skipped = [];

        DB::transaction(function () use ($automations, $user, $is_enabled, &$affected, &$skipped) {
            foreach ($automations as $automation) {
                $label = $automation->name ?: 'Automation';
                if (! $automation->board || ! BoardEditGate::allows($automation->board, $user)) {
                    $skipped[] = ['id' => $automation->id, 'message' => "You cannot edit the board of \"{$label}\"."];

                    continue;
                }
                if ($is_enabled && ($problems = $this->health_checker->problems($automation, fresh: true)) !== []) {
                    $skipped[] = ['id' => $automation->id, 'message' => "\"{$label}\": {$problems[0]['message']}"];

                    continue;
                }

                $automation->fill(['is_enabled' => $is_enabled, ...($is_enabled ? ['paused_at' => null, 'paused_reason' => null] : [])]);
                if ($is_enabled) {
                    $automation->forceFill(['consecutive_failures' => 0]);
                }
                $automation->save();
                $affected[] = $automation->id;
            }
        });

        $done = count($affected);

        return response()->json([
            'message' => $is_enabled ? "Turned on {$done} automation(s)." : "Turned off {$done} automation(s).",
            'affected_ids' => $affected,
            'skipped' => $skipped,
        ]);
    }

    /**
     * How many times each automation ran, and failed, in the last {@see self::RECENT_DAYS} days.
     *
     * @param  Collection<int, int>  $automation_ids
     * @return Collection<int, object{automation_id: int, runs: int, failed: int}>
     */
    private function recentRuns(Collection $automation_ids): Collection
    {
        if ($automation_ids->isEmpty()) {
            return collect();
        }

        return BoardAutomationRunLog::query()
            ->whereIn('automation_id', $automation_ids)
            ->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->selectRaw('automation_id, COUNT(*) as runs, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [BoardAutomationRunLog::STATUS_FAILED])
            ->groupBy('automation_id')
            ->get()
            ->keyBy('automation_id');
    }
}
