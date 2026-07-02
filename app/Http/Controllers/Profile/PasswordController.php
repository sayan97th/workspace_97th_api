<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    /**
     * PUT /api/profile/password
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
