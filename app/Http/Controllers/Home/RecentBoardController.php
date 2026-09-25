<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\BoardVisit;
use App\Services\Favorite\UserFavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentBoardController extends Controller
{
    /** How many boards the Home page's "Recently visited" section shows. */
    private const LIMIT = 12;

    /**
     * GET /api/home/recent-boards
     *
     * The boards the user opened most recently, newest first. Archived and
     * deleted boards drop out on their own.
     */
    public function index(Request $request, UserFavoriteService $favorites): JsonResponse
    {
        $user = $request->user();

        $visits = BoardVisit::query()
            ->where('user_id', $user->id)
            ->whereHas('board', fn ($query) => $query->where('is_archived', false))
            ->with('board.workspace:id,name,slug,color,mono')
            ->orderByDesc('visited_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'data' => $visits->map(fn (BoardVisit $visit) => [
                'id' => $visit->board->id,
                'label' => $visit->board->label,
                'view_key' => $visit->board->view_key,
                'board_type' => $visit->board->board_type,
                'is_favorite' => $favorites->isFavorite($visit->board->id, $user),
                'visited_at' => $visit->visited_at->toIso8601String(),
                'workspace' => $visit->board->workspace ? [
                    'id' => $visit->board->workspace->id,
                    'name' => $visit->board->workspace->name,
                    'slug' => $visit->board->workspace->slug,
                    'color' => $visit->board->workspace->color,
                    'mono' => $visit->board->workspace->mono,
                ] : null,
            ])->values(),
        ]);
    }
}
