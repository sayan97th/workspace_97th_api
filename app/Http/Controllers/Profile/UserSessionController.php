<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserSessionController extends Controller
{
    /**
     * GET /api/profile/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $current_jti = Auth::guard('api')->payload()->get('jti');

        $sessions = $request->user()->sessions()
            ->where('created_at', '>=', now()->subMonths(12))
            ->orderByDesc('last_used_at')
            ->get()
            ->map(function (UserSession $session) use ($current_jti) {
                $is_current_device = $session->jti === $current_jti;

                return [
                    'id' => (string) $session->id,
                    'device' => $session->device_label ?? 'Unknown device',
                    'ip' => $session->ip_address,
                    'last_used_at' => $session->last_used_at,
                    'is_current_device' => $is_current_device,
                    'can_logout' => ! $is_current_device && $session->revoked_at === null,
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    /**
     * DELETE /api/profile/sessions/{session}
     */
    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        $current_jti = Auth::guard('api')->payload()->get('jti');
        abort_if($session->jti === $current_jti, 422, "You can't log out of your current session from here.");

        $session->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Session logged out successfully.']);
    }
}
