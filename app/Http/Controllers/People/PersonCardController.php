<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonCardController extends Controller
{
    /**
     * GET /api/people/{user}/card
     *
     * The small profile card shown when hovering an `@mention` or an author's
     * avatar. Limited to people the viewer shares a workspace with (or the
     * viewer themselves), so it can't be used to look up arbitrary accounts.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();

        $shares_workspace = $viewer->id === $user->id
            || $user->workspaces()->whereIn('workspaces.id', $viewer->workspaces()->select('workspaces.id'))->exists();

        abort_unless($shares_workspace, 403);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'avatar_url' => $user->profile_photo_url,
                'timezone' => $user->timezone,
            ],
        ]);
    }
}
