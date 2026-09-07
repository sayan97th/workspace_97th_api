<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\AnalyzeBoardImportRequest;
use App\Http\Requests\Board\CommitBoardImportRequest;
use App\Http\Resources\BoardColumnResource;
use App\Http\Resources\BoardGroupResource;
use App\Http\Resources\BoardImportJobResource;
use App\Jobs\ProcessBoardImportJob;
use App\Models\BoardColumn;
use App\Models\BoardImportJob;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemImportService;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Board options menu's "More actions" > "Import items" — the three-step
 * ("Upload" / "Map columns" / "Handle matches") wizard's backend. `analyze()`
 * parses whatever file was dropped in and caches it server-side under a
 * short-lived token; `commit()` takes that token plus the wizard's final
 * column-mapping/duplicate-handling choices and queues
 * {@see ProcessBoardImportJob} to actually write the rows in the background
 * — `show()`/`cancel()` are the progress bar's REST fallback and its "stop"
 * button (see `useBoardImportProgress` on the frontend, which prefers the
 * live `board-import.{user_id}` broadcast channel over polling `show()`).
 * See {@see BoardItemImportService} for the parsing/import logic itself —
 * this controller only owns the request/response shape.
 */
class BoardImportController extends Controller
{
    public function __construct(
        private readonly BoardItemImportService $importer,
        private readonly BoardViewResolver $view_resolver,
    ) {}

    /**
     * POST /api/boards/{item}/import/analyze
     *
     * Step 1 ("Upload")'s submit — parses the dropped file, caches it to
     * disk under a fresh `import_token`, and returns everything the "Map
     * columns" step needs to render: each source column's label/sample
     * values/guessed type, a pre-filled best-guess mapping, and the target
     * tab's own existing columns + tables (for the "Add items to" picker).
     */
    public function analyze(AnalyzeBoardImportRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $view = $this->view_resolver->resolveForWrite($item, $request->validated('view_id'));

        $file = $request->file('file');
        $parsed = $this->importer->readSpreadsheet($file);

        if (count($parsed['rows']) === 0) {
            return response()->json([
                'message' => 'No rows were found in this file — make sure it has a header row and at least one data row.',
            ], 422);
        }

        if (count($parsed['rows']) > BoardItemImportService::MAX_ROWS) {
            return response()->json([
                'message' => 'This file has too many rows to import at once (max '.number_format(BoardItemImportService::MAX_ROWS).').',
            ], 422);
        }

        $import_token = $this->importer->storeParsedImport($file->getClientOriginalName(), $parsed['headers'], $parsed['rows']);

        $existing_columns = $view->columns()->where('scope', BoardColumn::SCOPE_ITEM)->orderBy('position')->get();
        $groups = $view->groups()->orderBy('position')->get();

        return response()->json([
            'import_token' => $import_token,
            'file_name' => $file->getClientOriginalName(),
            'row_count' => count($parsed['rows']),
            'source_columns' => $this->importer->buildSourceColumns($parsed['headers'], $parsed['rows']),
            'suggested_mappings' => $this->importer->suggestMappings($parsed['headers'], $existing_columns),
            'board_columns' => BoardColumnResource::collection($existing_columns),
            'groups' => BoardGroupResource::collection($groups),
            'creatable_column_types' => $this->importer->creatableColumnTypes(),
        ]);
    }

    /**
     * POST /api/boards/{item}/import/commit
     *
     * Step 3 ("Handle matches")'s final "Import Now" — loads the cached
     * upload back by `import_token`, creates a {@see BoardImportJob} row
     * (so there's something to show progress for immediately) and queues
     * {@see ProcessBoardImportJob} to actually write the rows in the
     * background. Returns 202 (accepted, not yet done) with the freshly
     * created job — the frontend switches to the wizard's progress step and
     * tracks that job's id from here on.
     */
    public function commit(CommitBoardImportRequest $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $validated = $request->validated();
        $view = $this->view_resolver->resolveForWrite($item, $validated['view_id'] ?? null);

        $parsed = $this->importer->loadParsedImport($validated['import_token']);

        if ($parsed === null) {
            return response()->json([
                'message' => 'This import has expired — please upload the file again.',
            ], 410);
        }

        $import_job = BoardImportJob::create([
            'board_id' => $item->id,
            'board_view_id' => $view->id,
            'user_id' => $request->user()->id,
            'import_token' => $validated['import_token'],
            'file_name' => $parsed['file_name'],
            'status' => BoardImportJob::STATUS_QUEUED,
            'total_rows' => count($parsed['rows']),
            'options' => [
                'target_group_id' => $validated['target_group_id'] ?? null,
                'new_group_name' => $validated['new_group_name'] ?? null,
                'mappings' => $validated['mappings'],
                'duplicate_mode' => $validated['duplicate_mode'],
                'match_source_index' => $validated['match_source_index'] ?? null,
            ],
        ]);

        ProcessBoardImportJob::dispatch($import_job->id);

        // On a `sync` queue connection (as in tests, and optionally local
        // dev) `dispatch()` above already ran the job to completion before
        // returning — but through a *different* `BoardImportJob` instance
        // than this one, so this in-memory `$import_job` is still showing
        // its pre-dispatch `"queued"` snapshot until refreshed.
        return response()->json([
            'message' => 'Import queued.',
            'data' => new BoardImportJobResource($import_job->fresh()),
        ], 202);
    }

    /**
     * GET /api/boards/{item}/import/{import_job}
     *
     * The progress bar's polling fallback for whenever the websocket
     * connection isn't up — otherwise it just relies on the live
     * `board_import_progress` broadcast.
     */
    public function show(WorkspaceNavigationItem $item, BoardImportJob $import_job): JsonResponse
    {
        $this->ensureImportJobBelongsToBoard($item, $import_job);

        return response()->json(['data' => new BoardImportJobResource($import_job)]);
    }

    /**
     * POST /api/boards/{item}/import/{import_job}/cancel
     *
     * The progress step's "Stop" button. Only flips a flag — the job itself
     * (see {@see ProcessBoardImportJob::handle()}) checks it between chunks
     * and stops there, keeping whatever it already committed rather than
     * rolling those rows back. No-ops once the job has already reached a
     * terminal status.
     */
    public function cancel(Request $request, WorkspaceNavigationItem $item, BoardImportJob $import_job): JsonResponse
    {
        $this->ensureImportJobBelongsToBoard($item, $import_job);

        if (! in_array($import_job->status, BoardImportJob::TERMINAL_STATUSES, true)) {
            $import_job->update(['cancel_requested' => true]);
        }

        return response()->json(['data' => new BoardImportJobResource($import_job->fresh())]);
    }

    /**
     * Guard: abort with 404 when the import job is not part of the board.
     */
    private function ensureImportJobBelongsToBoard(WorkspaceNavigationItem $item, BoardImportJob $import_job): void
    {
        abort_if($import_job->board_id !== $item->id, 404);
    }
}
