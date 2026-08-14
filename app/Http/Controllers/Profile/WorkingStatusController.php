<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateWorkingStatusRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;

class WorkingStatusController extends Controller
{
    /**
     * PATCH /api/profile/working-status
     */
    public function update(UpdateWorkingStatusRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Working status updated successfully.',
            'user' => new ProfileResource($user->fresh()),
        ]);
    }
}
