<?php

namespace App\Http\Controllers\Favorite;

use App\Http\Controllers\Controller;
use App\Models\UserFavoriteItem;
use App\Models\UserFavoriteWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Favorite\UserFavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar's personal "Favorites" section: every board and folder the
 * user starred, across all workspaces, in the user's own order, plus the
 * whole workspaces they starred (the section groups favorites by workspace).
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

        $favorite_workspaces = UserFavoriteWorkspace::query()
            ->where('user_id', $request->user()->id)
            ->with('workspace:id,name,slug,color,mono')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (UserFavoriteWorkspace $favorite) => $favorite->workspace !== null);

        return response()->json([
            'data' => $favorites->map(fn (UserFavoriteItem $favorite) => $this->present($favorite->navigationItem))->values(),
            'workspaces' => $favorite_workspaces->map(fn (UserFavoriteWorkspace $favorite) => $this->presentWorkspace($favorite->workspace))->values(),
        ]);
    }

    /**
     * PUT /api/favorites/workspaces/{workspace}
     *
     * Stars a whole workspace. A new one goes to the end of the list.
     */
    public function storeWorkspace(Request $request, Workspace $workspace): JsonResponse
    {
        $user_id = $request->user()->id;
        $next_position = (int) UserFavoriteWorkspace::query()->where('user_id', $user_id)->max('position') + 1;

        UserFavoriteWorkspace::query()->firstOrCreate(
            ['user_id' => $user_id, 'workspace_id' => $workspace->id],
            ['position' => $next_position]
        );

        return response()->json(['message' => 'Workspace added to favorites.', 'workspace' => $this->presentWorkspace($workspace)]);
    }

    /**
     * DELETE /api/favorites/workspaces/{workspace}
     */
    public function destroyWorkspace(Request $request, Workspace $workspace): JsonResponse
    {
        UserFavoriteWorkspace::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->delete();

        return response()->json(['message' => 'Workspace removed from favorites.']);
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
            'workspace' => $item->workspace ? $this->presentWorkspace($item->workspace) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWorkspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'color' => $workspace->color,
            'mono' => $workspace->mono,
        ];
    }
}
