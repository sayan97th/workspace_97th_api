<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\PatchProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new ProfileResource($request->user())]);
    }

    /**
     * PUT /api/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            // Older clients send PUT without a title, so it only changes when the field is present.
            ...(array_key_exists('job_title', $validated) ? ['job_title' => $validated['job_title']] : []),
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }

    /**
     * PATCH /api/profile
     */
    public function partialUpdate(PatchProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
