<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\StoreSavedSearchRequest;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The searches a user starred with "Save" in the Search Everything dialog.
 */
class SavedSearchController extends Controller
{
    /** Oldest saved searches beyond this many are not listed. */
    private const MAX_LISTED = 20;

    /**
     * GET /api/search/saved
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $saved = SavedSearch::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(self::MAX_LISTED)
            ->get(['id', 'query', 'tab', 'created_at']);

        return response()->json(['data' => $saved]);
    }

    /**
     * POST /api/search/saved
     *
     * Saving the same query and tab twice keeps a single entry.
     */
    public function store(StoreSavedSearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $saved = SavedSearch::query()->firstOrCreate([
            'user_id' => $user->id,
            'query' => $request->validated('query'),
            'tab' => $request->validated('tab'),
        ]);

        return response()->json([
            'message' => 'Search saved.',
            'data' => $saved->only(['id', 'query', 'tab', 'created_at']),
        ], $saved->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/search/saved/{saved_search}
     */
    public function destroy(Request $request, SavedSearch $saved_search): JsonResponse
    {
        abort_if($saved_search->user_id !== $request->user()?->id, 404);

        $saved_search->delete();

        return response()->json(['message' => 'Saved search removed.']);
    }
}
