<?php

namespace App\Http\Controllers\AccountTeam;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountTeam\StoreAccountTeamImageRequest;
use App\Http\Requests\AccountTeam\UpdateAccountTeamCoverPositionRequest;
use App\Http\Resources\AccountTeamResource;
use App\Models\AccountTeam;
use App\Services\AccountTeam\AccountTeamImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A team's profile picture and the cover image above its page. Admins and the team's own owners
 * may change both, the same people who manage its roster.
 */
class AccountTeamImageController extends Controller
{
    public function __construct(private readonly AccountTeamImageService $images) {}

    /**
     * POST /api/account-teams/{team}/picture
     */
    public function storePicture(StoreAccountTeamImageRequest $request, AccountTeam $team): JsonResponse
    {
        return $this->store($request, $team, AccountTeamImageService::KIND_PICTURE, 'Team picture updated successfully.');
    }

    /**
     * DELETE /api/account-teams/{team}/picture
     */
    public function destroyPicture(Request $request, AccountTeam $team): JsonResponse
    {
        return $this->destroy($request, $team, AccountTeamImageService::KIND_PICTURE, 'Team picture removed successfully.');
    }

    /**
     * POST /api/account-teams/{team}/cover
     */
    public function storeCover(StoreAccountTeamImageRequest $request, AccountTeam $team): JsonResponse
    {
        return $this->store($request, $team, AccountTeamImageService::KIND_COVER, 'Team cover updated successfully.');
    }

    /**
     * PATCH /api/account-teams/{team}/cover, saves where the cover is framed inside the banner.
     */
    public function updateCover(UpdateAccountTeamCoverPositionRequest $request, AccountTeam $team): JsonResponse
    {
        abort_unless($team->canBeManagedBy($request->user()), 403, 'You cannot manage this team.');
        abort_if($team->cover_path === null, 422, 'This team has no cover image to reposition.');

        $this->images->reposition($team, (int) $request->validated('cover_position_y'));

        return response()->json([
            'message' => 'Team cover repositioned successfully.',
            'team' => new AccountTeamResource($team->loadResourceData($request->user())),
        ]);
    }

    /**
     * DELETE /api/account-teams/{team}/cover
     */
    public function destroyCover(Request $request, AccountTeam $team): JsonResponse
    {
        return $this->destroy($request, $team, AccountTeamImageService::KIND_COVER, 'Team cover removed successfully.');
    }

    private function store(StoreAccountTeamImageRequest $request, AccountTeam $team, string $kind, string $message): JsonResponse
    {
        abort_unless($team->canBeManagedBy($request->user()), 403, 'You cannot manage this team.');

        $this->images->store(
            $team,
            $kind,
            $request->file('file'),
            (int) $request->validated('cover_position_y', AccountTeamImageService::DEFAULT_COVER_POSITION_Y),
        );

        return response()->json([
            'message' => $message,
            'team' => new AccountTeamResource($team->loadResourceData($request->user())),
        ]);
    }

    private function destroy(Request $request, AccountTeam $team, string $kind, string $message): JsonResponse
    {
        abort_unless($team->canBeManagedBy($request->user()), 403, 'You cannot manage this team.');

        $this->images->destroy($team, $kind);

        return response()->json([
            'message' => $message,
            'team' => new AccountTeamResource($team->loadResourceData($request->user())),
        ]);
    }
}
