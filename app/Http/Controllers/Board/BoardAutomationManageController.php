<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoardAutomationResource;
use App\Http\Resources\BoardAutomationRunLogResource;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationDelayedRun;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationSetting;
use App\Models\BoardAutomationVersion;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardAutomationHealthChecker;
use App\Services\Board\BoardAutomationService;
use App\Services\Board\BoardAutomationVersionRecorder;
use App\Support\AutomationCopier;
use App\Support\BoardEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * What the Manage tab does beyond one automation at a time: the board's automation settings
 * ("Pause all automations" and the working calendar), bulk enable, disable and delete, copying
 * automations to another board, version history, a run's steps with retry, and what the item
 * drawer's Automations tab shows for one item.
 */
class BoardAutomationManageController extends Controller
{
    private const MAX_BULK = 100;

    private const ITEM_RUNS_LIMIT = 50;

    public function __construct(
        private readonly BoardAutomationVersionRecorder $version_recorder,
        private readonly BoardAutomationHealthChecker $health_checker,
    ) {}

    /**
     * GET /api/boards/{item}/automations/settings
     */
    public function settings(WorkspaceNavigationItem $item): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload(BoardAutomationSetting::forBoard($item->id))]);
    }

    /**
     * PUT /api/boards/{item}/automations/settings
     */
    public function updateSettings(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());

        $validated = $request->validate([
            'is_paused' => ['sometimes', 'boolean'],
            'workdays' => ['sometimes', 'array', 'min:1', 'max:7'],
            'workdays.*' => ['integer', 'between:1,7', 'distinct'],
            'holidays' => ['sometimes', 'array', 'max:'.BoardAutomationSetting::MAX_HOLIDAYS],
            'holidays.*' => ['date_format:Y-m-d', 'distinct'],
        ]);

        $settings = BoardAutomationSetting::forBoard($item->id);
        if (array_key_exists('is_paused', $validated)) {
            $settings->paused_at = $validated['is_paused'] ? ($settings->paused_at ?? now()) : null;
            $settings->paused_by_id = $validated['is_paused'] ? $request->user()?->id : null;
        }
        if (array_key_exists('workdays', $validated)) {
            $workdays = array_map('intval', $validated['workdays']);
            sort($workdays);
            $settings->workdays = $workdays;
        }
        if (array_key_exists('holidays', $validated)) {
            $holidays = array_values($validated['holidays']);
            sort($holidays);
            $settings->holidays = $holidays;
        }
        $settings->save();

        return response()->json(['message' => 'Automation settings saved.', 'data' => $this->settingsPayload($settings->fresh('pausedBy'))]);
    }

    /**
     * POST /api/boards/{item}/automations/bulk
     *
     * Turns several automations on or off, or deletes them. An automation that uses something
     * deleted stays off and is listed under `skipped`.
     */
    public function bulk(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        BoardEditGate::authorize($item, $request->user());

        $validated = $request->validate([
            'automation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'automation_ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
        ]);

        $automations = BoardAutomation::where('board_id', $item->id)->whereIn('id', $validated['automation_ids'])->get();
        $skipped = [];

        DB::transaction(function () use ($automations, $validated, &$skipped) {
            foreach ($automations as $automation) {
                if ($validated['action'] === 'delete') {
                    $automation->delete();

                    continue;
                }

                $is_enabled = $validated['action'] === 'enable';
                if ($is_enabled && ($problems = $this->health_checker->problems($automation, fresh: true)) !== []) {
                    $skipped[] = ['id' => $automation->id, 'message' => $problems[0]['message']];

                    continue;
                }
                $automation->fill(['is_enabled' => $is_enabled, ...($is_enabled ? ['paused_at' => null, 'paused_reason' => null] : [])])->save();
            }
        });

        $done = $automations->count() - count($skipped);

        return response()->json([
            'message' => match ($validated['action']) {
                'delete' => "Deleted {$done} automation(s).",
                'enable' => "Turned on {$done} automation(s).",
                default => "Turned off {$done} automation(s).",
            },
            'affected_ids' => $automations->pluck('id')->diff(collect($skipped)->pluck('id'))->values(),
            'skipped' => $skipped,
        ]);
    }

    /**
     * POST /api/boards/{item}/automations/copy
     *
     * Copies automations onto the main tab of another board of the workspace, matching columns,
     * labels and groups by name, see {@see AutomationCopier}. Every copy starts switched off.
     */
    public function copy(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validate([
            'automation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'automation_ids.*' => ['integer', 'distinct'],
            'target_board_id' => ['required', 'integer'],
        ]);

        $target_board = WorkspaceNavigationItem::boards()->notArchived()->where('workspace_id', $item->workspace_id)->find($validated['target_board_id']);
        abort_if(! $target_board, 422, 'Choose a board of this workspace.');
        BoardEditGate::authorizeContent($target_board, $request->user());

        $target_view = BoardView::where('board_id', $target_board->id)->where('is_primary', true)->first()
            ?? BoardView::where('board_id', $target_board->id)->where('view_type', 'table')->orderBy('position')->first();
        abort_if(! $target_view, 422, 'The chosen board has no table to copy automations into.');

        $sources = BoardAutomation::where('board_id', $item->id)->whereIn('id', $validated['automation_ids'])->orderBy('id')->get();
        $results = [];

        foreach ($sources as $source) {
            $copied = (new AutomationCopier($source, $target_view))->copy();
            $actions = $copied['attributes']['actions'];

            $automation = BoardAutomation::create([
                'board_id' => $target_board->id,
                'board_view_id' => $target_view->id,
                'name' => $source->name,
                'description' => $source->description,
                'is_enabled' => false,
                'importance' => $source->importance,
                'failure_alert' => $source->failure_alert ?: BoardAutomation::FAILURE_ALERT_APP,
                ...$copied['attributes'],
                'action_type' => $actions[0]['type'],
                'action_params' => $actions[0]['params'],
                'last_scheduled_run_at' => in_array($source->trigger_type, BoardAutomation::scheduledTriggers(), true) ? now() : null,
                'webhook_token' => $source->trigger_type === BoardAutomation::TRIGGER_WEBHOOK_RECEIVED ? Str::random(48) : null,
                'created_by_id' => $request->user()?->id,
                'owner_id' => $request->user()?->id,
            ]);
            $this->version_recorder->record($automation, $request->user());

            $results[] = ['source_id' => $source->id, 'automation_id' => $automation->id, 'unmapped' => $copied['unmapped']];
        }

        return response()->json([
            'message' => 'Copied '.count($results)." automation(s) to \"{$target_board->label}\". They start turned off.",
            'target_board' => ['id' => $target_board->id, 'label' => $target_board->label],
            'data' => $results,
        ], 201);
    }

    /**
     * GET /api/boards/{item}/automations/{automation}/versions
     */
    public function versions(WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        abort_if($automation->board_id !== $item->id, 404);

        $versions = $automation->versions()->with('changedBy')->limit(BoardAutomationVersion::MAX_VERSIONS)->get();

        return response()->json([
            'data' => $versions->map(fn (BoardAutomationVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'snapshot' => $version->snapshot,
                'changed_parts' => $version->changed_parts ?? [],
                'changed_by' => $version->changedBy ? ['id' => $version->changedBy->id, 'name' => $version->changedBy->full_name] : null,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * POST /api/boards/{item}/automations/{automation}/versions/{version}/restore
     *
     * Writes an older version back as the newest one. A restored automation that uses something
     * deleted since is switched off, and the builder marks what to choose again.
     */
    public function restoreVersion(Request $request, WorkspaceNavigationItem $item, BoardAutomation $automation, BoardAutomationVersion $version): JsonResponse
    {
        abort_if($automation->board_id !== $item->id || $version->automation_id !== $automation->id, 404);
        BoardEditGate::authorize($item, $request->user());

        $snapshot = (array) $version->snapshot;
        $actions = array_values((array) ($snapshot['actions'] ?? []));
        abort_if($actions === [], 422, 'This version has no actions to restore.');

        $automation->fill([
            ...collect($snapshot)->only(BoardAutomationVersion::SNAPSHOT_FIELDS)->all(),
            'action_type' => $actions[0]['type'],
            'action_params' => $actions[0]['params'] ?? [],
            'last_scheduled_run_at' => in_array($snapshot['trigger_type'] ?? null, BoardAutomation::scheduledTriggers(), true) ? now() : $automation->last_scheduled_run_at,
        ]);
        if ($automation->is_enabled && $this->health_checker->problems($automation, fresh: true) !== []) {
            $automation->is_enabled = false;
        }
        $automation->save();
        $this->version_recorder->record($automation, $request->user());

        return response()->json([
            'message' => "Restored version {$version->version}.",
            'automation' => new BoardAutomationResource($automation->fresh()->load(['creator', 'owner'])->loadCount(['runLogs', 'versions'])->loadMax('runLogs', 'created_at')),
        ]);
    }

    /**
     * GET /api/boards/{item}/automations/runs/{run}
     *
     * Every step of the execution a run history row belongs to, and the retries of its failures.
     */
    public function run(WorkspaceNavigationItem $item, BoardAutomationRunLog $run): JsonResponse
    {
        abort_if($run->board_id !== $item->id, 404);

        $steps = $run->run_uuid
            ? BoardAutomationRunLog::where('run_uuid', $run->run_uuid)->with('actor')->orderBy('id')->get()
            : collect([$run->load('actor')]);
        $retries = BoardAutomationRunLog::whereIn('retry_of_id', $steps->pluck('id'))->with('actor')->orderBy('id')->get();
        $pending = $run->run_uuid ? BoardAutomationDelayedRun::where('run_uuid', $run->run_uuid)->where('status', BoardAutomationDelayedRun::STATUS_PENDING)->first() : null;
        $automation = $run->automation_id ? BoardAutomation::find($run->automation_id) : null;

        return response()->json(['data' => [
            'run' => new BoardAutomationRunLogResource($run->loadMissing('actor')),
            'steps' => BoardAutomationRunLogResource::collection($steps),
            'retries' => BoardAutomationRunLogResource::collection($retries),
            'waiting_until' => $pending?->run_at?->toIso8601String(),
            'waiting_id' => $pending?->id,
            'can_retry' => $automation !== null && $run->status === BoardAutomationRunLog::STATUS_FAILED,
        ]]);
    }

    /**
     * POST /api/boards/{item}/automations/runs/{run}/retry
     *
     * Runs a failed step again, and the steps after it, see {@see BoardAutomationService::retryRun()}.
     */
    public function retry(Request $request, WorkspaceNavigationItem $item, BoardAutomationRunLog $run, BoardAutomationService $automation_service): JsonResponse
    {
        abort_if($run->board_id !== $item->id, 404);
        BoardEditGate::authorize($item, $request->user());
        abort_if($run->status !== BoardAutomationRunLog::STATUS_FAILED, 422, 'Only a failed step can be retried.');
        abort_if(! $run->automation_id || ! BoardAutomation::whereKey($run->automation_id)->exists(), 422, 'The automation was deleted, so it cannot run again.');
        abort_if($run->board_item_id === null && $run->item_name !== null, 422, 'The item was deleted, so the step cannot run again.');

        $automation_service->retryRun($run, $request->user());
        $latest = BoardAutomationRunLog::where('retry_of_id', $run->id)->with('actor')->orderBy('id')->get();

        return response()->json([
            'message' => $latest->contains(fn (BoardAutomationRunLog $log) => $log->status === BoardAutomationRunLog::STATUS_FAILED) ? 'The retry failed again.' : 'Retried the step.',
            'data' => BoardAutomationRunLogResource::collection($latest),
        ]);
    }

    /**
     * DELETE /api/boards/{item}/automations/delayed/{delayed}
     *
     * Cancels the rest of a run that is waiting behind a "wait" step.
     */
    public function cancelDelayed(Request $request, WorkspaceNavigationItem $item, BoardAutomationDelayedRun $delayed): JsonResponse
    {
        abort_if($delayed->board_id !== $item->id, 404);
        BoardEditGate::authorize($item, $request->user());

        if ($delayed->status === BoardAutomationDelayedRun::STATUS_PENDING) {
            $delayed->forceFill(['status' => BoardAutomationDelayedRun::STATUS_CANCELLED])->save();
        }

        return response()->json(['message' => 'The waiting run was cancelled.']);
    }

    /**
     * GET /api/boards/{item}/automations/items/{board_item}
     *
     * The item drawer's Automations tab: the automations of the item's table with whether the item
     * passes their conditions right now, the runs on the item and the runs waiting on it.
     */
    public function forItem(WorkspaceNavigationItem $item, BoardItem $board_item, BoardAutomationService $automation_service): JsonResponse
    {
        abort_if($board_item->board_id !== $item->id, 404);
        $board_item->loadMissing(['group', 'values']);
        $view_id = $board_item->group?->board_view_id;

        $automations = BoardAutomation::where('board_view_id', $view_id)->orderByDesc('is_enabled')->orderByDesc('id')->get();
        $runs = BoardAutomationRunLog::where('board_item_id', $board_item->id)->with('actor')->orderByDesc('created_at')->orderByDesc('id')->limit(self::ITEM_RUNS_LIMIT)->get();
        $waiting = BoardAutomationDelayedRun::where('board_item_id', $board_item->id)->where('status', BoardAutomationDelayedRun::STATUS_PENDING)->orderBy('run_at')->get();

        return response()->json(['data' => [
            'automations' => $automations->map(fn (BoardAutomation $automation) => [
                'id' => $automation->id,
                'is_enabled' => $automation->is_enabled,
                'passes_conditions' => $automation->hasConditions() ? $automation_service->conditionsMatch($automation, $board_item) : null,
                'has_else' => $automation->resolvedElseActions() !== [],
            ])->values(),
            'runs' => BoardAutomationRunLogResource::collection($runs),
            'waiting' => $waiting->map(fn (BoardAutomationDelayedRun $delayed) => [
                'id' => $delayed->id,
                'automation_id' => $delayed->automation_id,
                'run_at' => $delayed->run_at->toIso8601String(),
            ])->values(),
            'is_board_paused' => $automation_service->isBoardPaused($item->id),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(BoardAutomationSetting $settings): array
    {
        return [
            'is_paused' => $settings->isPaused(),
            'paused_at' => $settings->paused_at?->toIso8601String(),
            'paused_by' => $settings->pausedBy ? ['id' => $settings->pausedBy->id, 'name' => $settings->pausedBy->full_name] : null,
            'workdays' => $settings->workdays ?: BoardAutomationSetting::DEFAULT_WORKDAYS,
            'holidays' => array_values((array) ($settings->holidays ?? [])),
        ];
    }
}
