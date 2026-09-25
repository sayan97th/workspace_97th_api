<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Http\Requests\Template\StoreBoardTemplateRequest;
use App\Http\Requests\Template\UseBoardTemplateRequest;
use App\Http\Resources\WorkspaceNavigationItemResource;
use App\Models\BoardActivityLog;
use App\Models\BoardTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardActivityLogger;
use App\Services\Board\BoardTemplateService;
use App\Support\BoardEditGate;
use App\Support\BoardVisibility;
use App\Support\BuiltInBoardTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Template center: the built in templates plus the boards people saved
 * as templates, saving a board as a template, and creating a board from one.
 */
class BoardTemplateController extends Controller
{
    public function __construct(
        private readonly BoardTemplateService $template_service,
        private readonly BoardActivityLogger $activity_logger,
    ) {}

    /**
     * GET /api/board-templates
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $built_in = collect(BuiltInBoardTemplates::all())->map(fn (array $template) => [
            'id' => 'builtin:'.$template['key'],
            'kind' => 'builtin',
            'name' => $template['name'],
            'description' => $template['description'],
            'category' => $template['category'],
            'color' => $template['color'],
            'includes_items' => true,
            'use_count' => 0,
            'creator' => null,
            'created_at' => null,
            'can_delete' => false,
            'preview' => $this->template_service->summarize($template['snapshot']),
        ]);

        $custom = BoardTemplate::with('creator')->latest()->get()->map(fn (BoardTemplate $template) => [
            'id' => 'custom:'.$template->id,
            'kind' => 'custom',
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'color' => $template->color,
            'includes_items' => $template->includes_items,
            'use_count' => $template->use_count,
            'creator' => $template->creator ? ['id' => $template->creator->id, 'full_name' => $template->creator->full_name] : null,
            'created_at' => $template->created_at,
            'can_delete' => $this->canManage($template, $user),
            'preview' => $this->template_service->summarize($template->snapshot),
        ]);

        return response()->json([
            'categories' => collect(BuiltInBoardTemplates::CATEGORIES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'templates' => $custom->concat($built_in)->values(),
        ]);
    }

    /**
     * POST /api/board-templates
     *
     * Saves a board the user can open as a reusable template.
     */
    public function store(StoreBoardTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $board = WorkspaceNavigationItem::findOrFail($validated['board_id']);

        abort_unless(BoardVisibility::canSee($user, $board->id), 404);
        BoardEditGate::authorize($board, $user);

        $include_items = (bool) ($validated['include_items'] ?? false);
        $template = BoardTemplate::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? 'custom',
            'color' => $board->workspace?->color,
            'created_by_id' => $user->id,
            'source_board_id' => $board->id,
            'includes_items' => $include_items,
            'snapshot' => $this->template_service->capture($board, $include_items),
        ]);

        return response()->json([
            'message' => 'Board saved as a template.',
            'template' => ['id' => 'custom:'.$template->id, 'name' => $template->name],
        ], 201);
    }

    /**
     * DELETE /api/board-templates/{board_template}
     */
    public function destroy(Request $request, BoardTemplate $board_template): JsonResponse
    {
        abort_unless($this->canManage($board_template, $request->user()), 403, 'Only the person who saved this template can delete it.');

        $board_template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * POST /api/workspaces/{workspace}/navigation/from-template
     */
    public function use(UseBoardTemplateRequest $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validated();
        [$kind, $key] = explode(':', $validated['template_id'], 2);

        if ($kind === 'builtin') {
            $built_in = BuiltInBoardTemplates::find($key);
            abort_if($built_in === null, 404, 'This template does not exist.');
            $snapshot = $built_in['snapshot'];
            $template_name = $built_in['name'];
        } else {
            $template = BoardTemplate::findOrFail((int) $key);
            $snapshot = $template->snapshot;
            $template_name = $template->name;
            $template->increment('use_count');
        }

        $board = $this->template_service->instantiate(
            $snapshot,
            $workspace,
            $validated['parent_id'] ?? null,
            $validated['label'],
            $request->user(),
            $validated['board_type'] ?? WorkspaceNavigationItem::BOARD_TYPE_MAIN,
        );

        $this->activity_logger->log($board, $request->user(), BoardActivityLog::ACTION_CREATED, "Created from the \"{$template_name}\" template");

        return response()->json([
            'message' => 'Board created from template.',
            'item' => new WorkspaceNavigationItemResource($board->load(['creator', 'workspace.owners'])),
        ], 201);
    }

    private function canManage(BoardTemplate $template, User $user): bool
    {
        return $template->created_by_id === $user->id || $user->hasRole(BoardVisibility::PRIVILEGED_GLOBAL_ROLES);
    }
}
