<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateSidebarPreferenceRequest;
use App\Http\Resources\ProfileResource;
use App\Support\SidebarPreferences;
use Illuminate\Http\JsonResponse;

class SidebarPreferenceController extends Controller
{
    /**
     * PATCH /api/profile/sidebar
     *
     * Persists the authenticated viewer's own workspace-sidebar layout, a
     * personal preference, not shared with other collaborators, mirroring
     * `LocalePreferenceController::update()`:
     *
     * - `width`: the drag handle's width (see `AppSidebar`).
     * - `sections`: order and visibility of Home, My work, Favorites and
     *   Recent (the "Customize sidebar" panel).
     * - `collapsed_sections`: which personal sections are folded.
     *
     * Only the fields sent are changed, see {@see SidebarPreferences}.
     */
    public function update(UpdateSidebarPreferenceRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $attributes = [];

        if (array_key_exists('width', $validated)) {
            $attributes['sidebar_width'] = $validated['width'];
        }

        if (array_key_exists('sections', $validated) || array_key_exists('collapsed_sections', $validated)) {
            $current = SidebarPreferences::normalize($user->sidebar_preferences);
            $attributes['sidebar_preferences'] = SidebarPreferences::normalize([
                'sections' => $validated['sections'] ?? $current['sections'],
                'collapsed_sections' => $validated['collapsed_sections'] ?? $current['collapsed_sections'],
            ]);
        }

        $user->update($attributes);

        return response()->json([
            'message' => 'Sidebar preferences saved successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
