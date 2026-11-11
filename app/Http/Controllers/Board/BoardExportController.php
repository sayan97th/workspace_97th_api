<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\StoreBoardExportRequest;
use App\Http\Resources\BoardExportJobResource;
use App\Jobs\ProcessBoardExportJob;
use App\Models\BoardExportJob;
use App\Models\BoardGroup;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardViewResolver;
use App\Services\Board\Export\BoardExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Board options menu's "More actions" > "Export board to Excel", and the
 * group menu's "Export to Excel".
 *
 * Exports run in the background: `store()` only records the request and
 * queues {@see ProcessBoardExportJob} on the `exports` queue, the dialog
 * follows its progress (websocket, with `show()` as the polling fallback)
 * and then opens the signed `download()` link. No request ever builds a
 * workbook itself, so a board with a huge number of rows can't hold up the
 * web server for anyone else.
 */
class BoardExportController extends Controller
{
    public function __construct(
        private readonly BoardViewResolver $view_resolver,
        private readonly BoardExportService $exporter,
    ) {}

    /**
     * GET /api/boards/{item}/exports/summary?view_id=&group_id=
     *
     * The counts the export dialog shows before anything runs.
     */
    public function summary(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        [$view, $group] = $this->resolveScope($item, $request->integer('view_id') ?: null, $request->integer('group_id') ?: null);

        return response()->json([
            'data' => [
                'board_label' => $item->label,
                'view_label' => $view->label,
                'group_name' => $group?->name,
                ...$this->exporter->summary($item, $view, $group),
            ],
        ]);
    }

    /**
     * POST /api/boards/{item}/exports
     *
     * Queues an export. Pressing "Export" twice with the same choices while the first one is
     * still running returns that one rather than queuing a duplicate.
     */
    public function store(StoreBoardExportRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        [$view, $group] = $this->resolveScope($item, $validated['view_id'] ?? null, $validated['group_id'] ?? null);

        $options = [
            'layout' => $validated['layout'] ?? BoardExportJob::LAYOUT_GROUPED,
            'include_subitems' => (bool) ($validated['include_subitems'] ?? true),
            'include_hidden_columns' => (bool) ($validated['include_hidden_columns'] ?? true),
            'time_zone' => $validated['time_zone'] ?? 'UTC',
        ];

        $active = BoardExportJob::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [BoardExportJob::STATUS_QUEUED, BoardExportJob::STATUS_PROCESSING])
            ->where('cancel_requested', false)
            ->get();

        $same = $active->first(fn (BoardExportJob $job) => $job->board_id === $item->id
            && $job->board_view_id === $view->id
            && $job->group_id === $group?->id
            && $job->options == $options);

        if ($same !== null) {
            return response()->json(['message' => 'Export already in progress.', 'data' => new BoardExportJobResource($same)], 202);
        }

        if ($active->count() >= BoardExportJob::MAX_ACTIVE_PER_USER) {
            return response()->json([
                'message' => 'You already have '.BoardExportJob::MAX_ACTIVE_PER_USER.' exports running. Please wait for one of them to finish.',
            ], 429);
        }

        $export_job = BoardExportJob::create([
            'board_id' => $item->id,
            'board_view_id' => $view->id,
            'group_id' => $group?->id,
            'user_id' => $user->id,
            'status' => BoardExportJob::STATUS_QUEUED,
            'options' => $options,
            'file_name' => BoardExportService::fileName($item, $group),
        ]);

        ProcessBoardExportJob::dispatch($export_job->id);

        // On a `sync` queue the job already ran through another instance of the row.
        return response()->json([
            'message' => 'Export queued.',
            'data' => new BoardExportJobResource($export_job->fresh()),
        ], 202);
    }

    /**
     * GET /api/boards/{item}/exports/{export_job}
     *
     * The dialog's polling fallback for when the websocket isn't connected.
     */
    public function show(Request $request, WorkspaceNavigationItem $item, BoardExportJob $export_job): JsonResponse
    {
        $this->ensureOwnExport($request, $item, $export_job);

        return response()->json(['data' => new BoardExportJobResource($export_job)]);
    }

    /**
     * POST /api/boards/{item}/exports/{export_job}/cancel
     *
     * Only flips a flag, the job stops at its next chunk and throws the partial file away.
     */
    public function cancel(Request $request, WorkspaceNavigationItem $item, BoardExportJob $export_job): JsonResponse
    {
        $this->ensureOwnExport($request, $item, $export_job);

        if (! $export_job->isTerminal()) {
            $export_job->update(['cancel_requested' => true]);

            // Still waiting in the queue: no worker will report back for a while, so settle it now.
            if ($export_job->status === BoardExportJob::STATUS_QUEUED) {
                $export_job->update(['status' => BoardExportJob::STATUS_CANCELLED, 'finished_at' => now()]);
            }
        }

        return response()->json(['data' => new BoardExportJobResource($export_job->fresh())]);
    }

    /**
     * GET /api/board-exports/{export_job}/download?expires=&signature=
     *
     * Streams the finished workbook. Reached through the signed, short lived
     * `download_url` of {@see BoardExportJobResource}, so the browser downloads
     * it natively (no JWT header, nothing buffered in JavaScript).
     */
    public function download(BoardExportJob $export_job): Response
    {
        abort_unless($export_job->isDownloadable(), 410, 'This export has expired. Please export the board again.');

        $disk = Storage::disk($export_job->disk ?? config('filesystems.default'));
        abort_unless($disk->exists((string) $export_job->file_path), 410, 'This export has expired. Please export the board again.');

        $export_job->forceFill(['downloaded_at' => now()])->save();

        return $disk->download((string) $export_job->file_path, $export_job->file_name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The tab to export (the given one or the primary tab) and, from the group menu, the group.
     *
     * @return array{0: BoardView, 1: BoardGroup|null}
     */
    private function resolveScope(WorkspaceNavigationItem $item, ?int $view_id, ?int $group_id): array
    {
        $view = $this->view_resolver->resolveForRead($item, $view_id);
        abort_if($view === null, 404, 'This board has no table to export.');

        $group = null;
        if ($group_id !== null) {
            $group = $item->groups()->where('is_archived', false)->findOrFail($group_id);
            if ($group->board_view_id !== $view->id) {
                $view = $item->views()->find($group->board_view_id) ?? $view;
            }
        }

        return [$view, $group];
    }

    /** Someone else's export, or one of another board, reads as not found. */
    private function ensureOwnExport(Request $request, WorkspaceNavigationItem $item, BoardExportJob $export_job): void
    {
        abort_if($export_job->board_id !== $item->id || $export_job->user_id !== $request->user()->id, 404);
    }
}
