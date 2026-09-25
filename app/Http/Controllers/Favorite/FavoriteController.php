<?php

namespace App\Http\Controllers\Favorite;

use App\Http\Controllers\Controller;
use App\Models\UserFavoriteItem;
use App\Models\WorkspaceNavigationItem;
use App\Services\Favorite\UserFavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar's personal "Favorites" section: every board and folder the
 * user starred, across all workspaces, in the user's own order.
 */
class FavoriteController extends Controller
{
    public function __construct(private readonly UserFavoriteService $favorites) {}

    /**
     * GET /api/favorites
     */
    public function index(Request $request): JsonResponse
    {
        $favorites = UserFavoriteItem::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('navigationItem', fn ($query) => $query->where('is_archived', false))
            ->with('navigationItem.workspace:id,name,slug,color,mono')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $favorites->map(fn (UserFavoriteItem $favorite) => $this->present($favorite->navigationItem))->values(),
        ]);
    }

    /**
     * PUT /api/favorites/{item}
     */
    public function store(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->favorites->set($request->user(), $item->id, true);

        return response()->json(['message' => 'Added to favorites.', 'item' => $this->present($item->load('workspace:id,name,slug,color,mono'))]);
    }

    /**
     * DELETE /api/favorites/{item}
     */
    public function destroy(Request $request, WorkspaceNavigationItem $item): JsonResponse
    {
        $this->favorites->set($request->user(), $item->id, false);

        return response()->json(['message' => 'Removed from favorites.']);
    }

    /**
     * PUT /api/favorites/order
     *
     * Persists a drag and drop reorder of the whole Favorites list.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'max:500'],
            'item_ids.*' => ['integer', 'distinct'],
        ]);

        DB::transaction(function () use ($request, $validated) {
            foreach ($validated['item_ids'] as $position => $item_id) {
                UserFavoriteItem::query()
                    ->where('user_id', $request->user()->id)
                    ->where('navigation_item_id', $item_id)
                    ->update(['position' => $position]);
            }
        });

        return response()->json(['message' => 'Favorites reordered.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WorkspaceNavigationItem $item): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'type' => $item->type,
            'view_key' => $item->view_key,
            'board_type' => $item->board_type,
            'workspace' => $item->workspace ? [
                'id' => $item->workspace->id,
                'name' => $item->workspace->name,
                'slug' => $item->workspace->slug,
                'color' => $item->workspace->color,
                'mono' => $item->workspace->mono,
            ] : null,
        ];
    }
}
