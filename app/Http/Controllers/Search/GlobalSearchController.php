<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\GlobalSearchRequest;
use App\Models\User;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;

/**
 * The top bar's "Search for anything..." box (`GlobalSearch` on the frontend).
 */
class GlobalSearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $search_service,
    ) {}

    /**
     * GET /api/search?q=&limit=
     *
     * Workspaces, boards and items matching `q`, grouped by kind and capped to
     * `limit` rows each (default {@see GlobalSearchService::DEFAULT_LIMIT}).
     * Only ever returns what the current user can open, see
     * {@see GlobalSearchService} for the exact visibility rules.
     */
    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $limit = $request->integer('limit', GlobalSearchService::DEFAULT_LIMIT);

        return response()->json([
            'data' => $this->search_service->search($user, $request->validated('q'), $limit),
            'meta' => ['query' => $request->validated('q')],
        ]);
    }
}
