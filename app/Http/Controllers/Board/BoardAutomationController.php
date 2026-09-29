<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardAutomationRequest;
use App\Http\Requests\Board\TestBoardAutomationRequest;
use App\Http\Requests\Board\UpdateBoardAutomationRequest;
use App\Http\Resources\BoardAutomationResource;
use App\Http\Resources\BoardAutomationRunLogResource;
use App\Http\Resources\BoardAutomationTemplateResource;
use App\Models\AccountTeam;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationRunLog;
use App\Models\BoardAutomationTemplate;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\AutomationDynamicValueResolver;
use App\Services\Board\AutomationUsageMeter;
use App\Services\Board\BoardAutomationHealthChecker;
use App\Services\Board\BoardAutomationImpactPreview;
use App\Services\Board\BoardAutomationRunUndoer;
use App\Services\Board\BoardAutomationService;
use App\Services\Board\BoardAutomationVersionRecorder;
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

    public function __construct(
        private readonly BoardViewResolver $view_resolver,
        private readonly BoardAutomationHealthChecker $health_checker,
        private readonly BoardAutomationVersionRecorder $version_recorder,
        private readonly AutomationUsageMeter $usage_meter,
    ) {}

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
            ->with(['creator', 'owner'])
            ->withCount(['runLogs', 'versions'])
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
            'description' => $validated['description'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'importance' => $validated['importance'] ?? BoardAutomation::IMPORTANCE_MINOR,
            'failure_alert' => $validated['failure_alert'] ?? BoardAutomation::FAILURE_ALERT_APP,
            ...$this->definitionAttributes($validated),
            'created_by_id' => $request->user()?->id,
            'owner_id' => $request->user()?->id,
        ]);
        $this->ensureWebhookToken($automation);
        $this->version_recorder->record($automation, $request->user());

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

        $validated = $request->validated();
        $automation->fill(collect($validated)->only(['name', 'description', 'is_enabled', 'importance', 'owner_id', 'failure_alert'])->all());

        // The sentence builder always saves the whole definition, so a changed trigger never keeps
        // the column, value or config of the one it replaced.
        $is_definition_saved = array_key_exists('trigger_type', $validated) || array_key_exists('actions', $validated);
        if ($is_definition_saved) {
            $automation->fill($this->definitionAttributes([
                'trigger_type' => $automation->trigger_type,
                'trigger_column_id' => $automation->trigger_column_id,
                'trigger_value' => $automation->trigger_value,
                'trigger_config' => $automation->trigger_config,
                'conditions' => $automation->conditions,
                'condition_operator' => $automation->condition_operator,
                'condition_groups' => $automation->condition_groups,
                'actions' => $automation->resolvedActions(),
                'else_actions' => $automation->resolvedElseActions(),
                ...$validated,
            ]));
        }

        // A paused automation is switched back on only once nothing it uses is missing.
        if (($validated['is_enabled'] ?? false) === true && ($problems = $this->health_checker->problems($automation, fresh: true)) !== []) {
            return response()->json([
                'message' => 'Fix this automation before turning it on: '.lcfirst($problems[0]['message']),
                'errors' => ['is_enabled' => [$problems[0]['message']]],
            ], 422);
        }
        if ($is_definition_saved || $automation->is_enabled) {
            $automation->fill(['paused_at' => null, 'paused_reason' => null]);
        }
        // Turning it back on, or changing what it does, starts the failure count again.
        if ($is_definition_saved || ($validated['is_enabled'] ?? null) === true) {
            $automation->forceFill(['consecutive_failures' => 0]);
        }

        $automation->save();
        $this->ensureWebhookToken($automation);
        $this->version_recorder->record($automation, $request->user());

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
        $copy->owner_id = $request->user()?->id;
        $copy->last_scheduled_run_at = in_array($automation->trigger_type, BoardAutomation::scheduledTriggers(), true) ? now() : null;
        $copy->webhook_token = null;
        $copy->paused_at = null;
        $copy->paused_reason = null;
        $copy->state = null;
        $copy->save();
        $this->ensureWebhookToken($copy);
        $this->version_recorder->record($copy, $request->user());

        return response()->json([
            'message' => 'Automation duplicated successfully.',
            'automation' => new BoardAutomationResource($this->withRunStats($copy)),
        ], 201);
    }

    /**
     * POST /api/boards/{item}/automations/test
     *
     * "Test run on an item": runs a builder definition (saved or not) once on `item_id`, inside a
     * transaction that is rolled back, with nothing sent outside the app. Answers with which
     * conditions the item passes and what every action did or would have done.
     */
    public function test(TestBoardAutomationRequest $request, WorkspaceNavigationItem $item, BoardAutomationService $automation_service): JsonResponse
    {
        $validated = $request->validated();
        $automation = $this->unsavedAutomation($validated, $item, $request);

        $board_item = isset($validated['item_id']) ? BoardItem::with(['group', 'values'])->find($validated['item_id']) : null;
        $result = $automation_service->testRun($automation, $board_item, $request->user(), (array) ($validated['payload'] ?? []));

        return response()->json(['data' => [
            'item' => $board_item ? ['id' => $board_item->id, 'name' => $board_item->name] : null,
            ...$result,
        ]]);
    }

    /**
     * POST /api/boards/{item}/automations/preview
     *
     * "Preview impact": the items of the table an automation that is not saved yet would act on,
     * and what one run would do, see {@see BoardAutomationImpactPreview}. Nothing is saved.
     */
    public function preview(TestBoardAutomationRequest $request, WorkspaceNavigationItem $item, BoardAutomationImpactPreview $impact_preview): JsonResponse
    {
        $automation = $this->unsavedAutomation($request->validated(), $item, $request);

        return response()->json(['data' => $impact_preview->preview($automation, $request->user())]);
    }

    /**
     * The automation a test run or a preview works with, built from the builder's definition.
     *
     * @param  array<string, mixed>  $validated
     */
    private function unsavedAutomation(array $validated, WorkspaceNavigationItem $item, Request $request): BoardAutomation
    {
        $automation = new BoardAutomation([
            'board_id' => $item->id,
            'board_view_id' => $validated['view_id'],
            'name' => $validated['name'] ?? null,
            'is_enabled' => true,
            'importance' => BoardAutomation::IMPORTANCE_MINOR,
            ...$this->definitionAttributes($validated),
            'created_by_id' => $request->user()?->id,
            'owner_id' => $request->user()?->id,
        ]);
        $automation->setRelation('board', $item);
        $automation->setRelation('owner', $request->user());
        $automation->setRelation('creator', $request->user());

        return $automation;
    }

    /**
     * POST /api/boards/{item}/automations/{automation}/webhook-token
     *
     * Replaces a webhook automation's secret URL, the old one stops working at once.
     */
    public function regenerateWebhookToken(WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);
        abort_if($automation->trigger_type !== BoardAutomation::TRIGGER_WEBHOOK_RECEIVED, 422, 'This automation does not listen to a webhook.');

        $automation->forceFill(['webhook_token' => $this->newWebhookToken()])->save();

        return response()->json([
            'message' => 'Webhook URL replaced.',
            'automation' => new BoardAutomationResource($this->withRunStats($automation)),
        ]);
    }

    /**
     * GET /api/boards/{item}/automations/teams
     *
     * The account teams a "notify team" action can reach, with how many people each has.
     */
    public function teams(): JsonResponse
    {
        $teams = AccountTeam::withCount('members')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $teams->map(fn (AccountTeam $team) => ['id' => $team->id, 'name' => $team->name, 'member_count' => (int) $team->members_count])->values(),
        ]);
    }

    /**
     * GET /api/boards/{item}/automations/runs
     *
     * The Manage tab's "Run history", newest first. Scoped to one tab like `index()`, and
     * narrowed by `status`, `automation_id`, `item_id` and a `from`/`to` date range.
     */
    public function runs(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $filters = $request->validate([
            'view_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(BoardAutomationRunLog::statuses())],
            'automation_id' => ['sometimes', 'nullable', 'integer'],
            'item_id' => ['sometimes', 'nullable', 'integer'],
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
            ->when($filters['item_id'] ?? null, fn ($query, $item_id) => $query->where('board_item_id', $item_id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($per_page);

        $undoable = BoardAutomationRunUndoer::undoableRunUuids($page->getCollection()->pluck('run_uuid')->all());
        $page->getCollection()->each(fn (BoardAutomationRunLog $log) => $log->setAttribute('can_undo', $log->run_uuid !== null && in_array($log->run_uuid, $undoable, true)));

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
            'monthly_quota' => $this->usage_meter->quota(),
        ];
    }

    /**
     * Adds what the Manage tab shows next to an automation, its run count, last run and creator.
     */
    private function withRunStats(BoardAutomation $automation): BoardAutomation
    {
        return $automation->load(['creator', 'owner'])->loadCount(['runLogs', 'versions'])->loadMax('runLogs', 'created_at');
    }

    /**
     * GET /api/boards/{item}/automations/templates
     *
     * The board's saved templates, newest first, shown in the Create tab next to the built-in ones.
     */
    public function templates(WorkspaceNavigationItem $item): JsonResponse
    {
        $templates = BoardAutomationTemplate::where('board_id', $item->id)->with('creator')->latest('id')->get();

        return response()->json(['data' => BoardAutomationTemplateResource::collection($templates)]);
    }

    /**
     * POST /api/boards/{item}/automations/{automation}/template
     *
     * "Save as template" on an automation card, copies its definition (not its state).
     */
    public function saveAsTemplate(Request $request, WorkspaceNavigationItem $item, BoardAutomation $automation): JsonResponse
    {
        $this->ensureAutomationBelongsToBoard($item, $automation);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $template = BoardAutomationTemplate::create([
            'board_id' => $item->id,
            'name' => trim((string) ($validated['name'] ?? '')) ?: ($automation->name ?: 'Saved automation'),
            'description' => $validated['description'] ?? $automation->description,
            'definition' => [
                'trigger_type' => $automation->trigger_type,
                'trigger_column_id' => $automation->trigger_column_id,
                'trigger_value' => $automation->trigger_value,
                'trigger_config' => $automation->trigger_config,
                'conditions' => $automation->conditions ?? [],
                'condition_operator' => $automation->condition_operator ?: 'and',
                'condition_groups' => $automation->condition_groups ?? [],
                'actions' => $automation->resolvedActions(),
                'else_actions' => $automation->resolvedElseActions(),
            ],
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Template saved successfully.',
            'template' => new BoardAutomationTemplateResource($template->load('creator')),
        ], 201);
    }

    /**
     * DELETE /api/boards/{item}/automations/templates/{template}
     */
    public function destroyTemplate(WorkspaceNavigationItem $item, BoardAutomationTemplate $template): JsonResponse
    {
        abort_if($template->board_id !== $item->id, 404);

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully.']);
    }

    /**
     * The columns an automation's trigger, conditions and actions are stored in, read from a
     * validated sentence builder payload. `action_type`/`action_params` mirror the first action.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function definitionAttributes(array $validated): array
    {
        $trigger_type = (string) $validated['trigger_type'];
        $to_actions = fn (mixed $list) => array_values(array_map(
            fn (array $action) => ['type' => (string) $action['type'], 'params' => (array) ($action['params'] ?? [])],
            array_filter((array) ($list ?? []), 'is_array')
        ));
        $to_rule = fn (array $rule) => [
            'column_id' => (string) $rule['column_id'],
            'condition' => (string) $rule['condition'],
            'value' => (string) ($rule['value'] ?? ''),
            'values' => array_values(array_map('strval', array_filter((array) ($rule['values'] ?? []), 'is_scalar'))),
            // A value read on every run ("today + 3 days", "the item creator"), see `AutomationDynamicValueResolver`.
            ...(AutomationDynamicValueResolver::isDynamic($rule['dynamic'] ?? null) ? ['dynamic' => array_filter([
                'source' => (string) $rule['dynamic']['source'],
                'offset_days' => isset($rule['dynamic']['offset_days']) ? (int) $rule['dynamic']['offset_days'] : null,
                'use_working_days' => ! empty($rule['dynamic']['use_working_days']) ? true : null,
                'column_id' => isset($rule['dynamic']['column_id']) ? (int) $rule['dynamic']['column_id'] : null,
            ], fn ($value) => $value !== null)] : []),
        ];
        // A "subitems" condition keeps its nested rule on a subitem column.
        $to_rules = fn (mixed $list) => array_values(array_map(
            fn (array $rule) => [...$to_rule($rule), ...(is_array($rule['subitem_rule'] ?? null) ? ['subitem_rule' => $to_rule($rule['subitem_rule'])] : [])],
            array_filter((array) ($list ?? []), 'is_array')
        ));
        $actions = $to_actions($validated['actions'] ?? []);
        $else_actions = $to_actions($validated['else_actions'] ?? []);
        $conditions = $to_rules($validated['conditions'] ?? []);
        $condition_groups = array_values(array_filter(array_map(
            fn (array $group) => ['join_operator' => ($group['join_operator'] ?? 'and') === 'or' ? 'or' : 'and', 'rules' => $to_rules($group['rules'] ?? [])],
            array_filter((array) ($validated['condition_groups'] ?? []), 'is_array')
        ), fn (array $group) => $group['rules'] !== []));
        $trigger_config = array_filter((array) ($validated['trigger_config'] ?? []), fn ($value) => $value !== null && $value !== '');

        return [
            'trigger_type' => $trigger_type,
            'trigger_column_id' => in_array($trigger_type, BoardAutomation::columnTriggers(), true) ? ($validated['trigger_column_id'] ?? null) : null,
            'trigger_value' => $validated['trigger_value'] ?? null,
            'trigger_config' => $trigger_config ?: null,
            'conditions' => $conditions ?: null,
            'condition_operator' => ($validated['condition_operator'] ?? 'and') === 'or' ? 'or' : 'and',
            'condition_groups' => $condition_groups ?: null,
            'actions' => $actions,
            'else_actions' => $else_actions ?: null,
            'action_type' => $actions[0]['type'],
            'action_params' => $actions[0]['params'],
            // A schedule counts from the moment it is saved, never catching up on the past.
            'last_scheduled_run_at' => in_array($trigger_type, BoardAutomation::scheduledTriggers(), true) ? now() : null,
        ];
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
     * Gives a webhook automation its secret URL the first time it is saved with that trigger.
     */
    private function ensureWebhookToken(BoardAutomation $automation): void
    {
        if ($automation->trigger_type === BoardAutomation::TRIGGER_WEBHOOK_RECEIVED && ! $automation->webhook_token) {
            $automation->forceFill(['webhook_token' => $this->newWebhookToken()])->save();
        }
    }

    private function newWebhookToken(): string
    {
        return Str::random(48);
    }

    /**
     * Guard: abort with 404 when the automation is not part of the board.
     */
    private function ensureAutomationBelongsToBoard(WorkspaceNavigationItem $item, BoardAutomation $automation): void
    {
        abort_if($automation->board_id !== $item->id, 404);
    }
}
