<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateSidebarPreferenceRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;

class SidebarPreferenceController extends Controller
{
    /**
     * PATCH /api/profile/sidebar
     *
     * Persists the authenticated viewer's own workspace-sidebar width (see
     * `AppSidebar`'s drag handle), a personal preference, not shared with
     * other collaborators, mirroring `LocalePreferenceController::update()`.
     */
    public function update(UpdateSidebarPreferenceRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['sidebar_width' => $request->validated('width')]);

        return response()->json([
            'message' => 'Sidebar width saved successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
