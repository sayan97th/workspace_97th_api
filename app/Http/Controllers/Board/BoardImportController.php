<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Http\Requests\Board\AnalyzeBoardImportRequest;
use App\Http\Requests\Board\CommitBoardImportRequest;
use App\Http\Resources\BoardColumnResource;
use App\Http\Resources\BoardGroupResource;
use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemImportService;
use App\Services\Board\BoardViewResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Board options menu's "More actions" > "Import items" — the three-step
 * ("Upload" / "Map columns" / "Handle matches") wizard's backend. `analyze()`
 * parses whatever file was dropped in and caches it server-side under a
 * short-lived token; `commit()` takes that token plus the wizard's final
 * column-mapping/duplicate-handling choices and actually writes the rows.
 * See {@see BoardItemImportService} for the parsing/import logic itself —
 * this controller only owns the request/response shape and the transaction
 * boundary.
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
     * upload back by `import_token` and writes it, wrapped in one
     * transaction so a mid-import failure can't leave a half-created table.
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

        $result = DB::transaction(fn () => $this->importer->commit($item, $view, $parsed, [
            'target_group_id' => $validated['target_group_id'] ?? null,
            'new_group_name' => $validated['new_group_name'] ?? null,
            'mappings' => $validated['mappings'],
            'duplicate_mode' => $validated['duplicate_mode'],
            'match_source_index' => $validated['match_source_index'] ?? null,
        ]));

        $this->importer->deleteParsedImport($validated['import_token']);

        return response()->json([
            'message' => 'Import completed successfully.',
            ...$result,
        ]);
    }
}
