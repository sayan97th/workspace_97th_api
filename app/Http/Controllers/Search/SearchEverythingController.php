<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchEverythingRequest;
use App\Services\Search\Everything\SearchEverythingService;
use Illuminate\Http\JsonResponse;

/**
 * The "Search Everything" dialog (`SearchEverythingModal` on the frontend), opened from the top
 * bar search or by clicking a tag in a Tags cell.
 */
class SearchEverythingController extends Controller
{
    public function __construct(
        private readonly SearchEverythingService $search_service,
    ) {}

    /**
     * GET /api/search/everything?q=&tab=&sort=&date_from=&date_to=&include_archived=&filters[]=&file_types[]=&page=&per_page=
     *
     * One page of the requested tab plus, on the first page, the count of every tab and the
     * facet counts of the requested tab. Only returns what the user can open.
     */
    public function __invoke(SearchEverythingRequest $request): JsonResponse
    {
        return response()->json($this->search_service->search(
            $request->criteria(),
            $request->validated('tab') ?? SearchEverythingService::TAB_ALL,
            $request->integer('page', 1),
            $request->integer('per_page', SearchEverythingService::DEFAULT_PER_PAGE),
        ));
    }
}
