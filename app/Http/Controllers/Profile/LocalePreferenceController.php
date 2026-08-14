<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateLocalePreferencesRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;

class LocalePreferenceController extends Controller
{
    /**
     * PATCH /api/profile/locale
     */
    public function update(UpdateLocalePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Language & region preferences updated successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
