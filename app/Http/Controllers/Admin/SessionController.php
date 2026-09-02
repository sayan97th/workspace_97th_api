<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Profile\UserSessionController;
use App\Http\Resources\AdminUserSessionResource;
use App\Models\UserSession;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Account-wide session management, the admin-scoped sibling of
 * {@see UserSessionController} (which only ever touches the
 * authenticated user's own sessions). Revoking a session here takes effect immediately: the
 * existing `session.active` middleware already rejects any request whose JWT `jti` maps to a
 * revoked row, no new enforcement needed.
 */
class SessionController extends Controller
{
    /**
     * GET /api/admin/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');

        $query = UserSession::with('user:id,first_name,last_name,profile_photo_path')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->orderByDesc('last_used_at');

        if ($search !== null && $search !== '') {
            $query->whereHas('user', function ($user_query) use ($search) {
                $user_query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $per_page = min((int) $request->query('per_page', 25), 200);
        $sessions = $query->paginate($per_page);

        return response()->json([
            'data' => AdminUserSessionResource::collection($sessions->items()),
            'current_page' => $sessions->currentPage(),
            'last_page' => $sessions->lastPage(),
            'total' => $sessions->total(),
        ]);
    }

    /**
     * DELETE /api/admin/sessions/{session}
     */
    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        $current_jti = Auth::guard('api')->payload()->get('jti');
        abort_if($session->jti === $current_jti, 422, "You can't log out of your current session from here.");

        $session->load('user:id,first_name,last_name');
        $session->update(['revoked_at' => now()]);

        AuditLogger::log(
            'session.revoked',
            "Logged out {$session->user?->full_name}'s session.",
            $request->user(),
            ['target_user_id' => $session->user_id, 'session_id' => $session->id]
        );

        return response()->json(['message' => 'Session logged out successfully.']);
    }

    /**
     * DELETE /api/admin/sessions
     *
     * Revokes every active session account-wide except the caller's own current device, so
     * the admin who triggered this stays signed in.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $current_jti = Auth::guard('api')->payload()->get('jti');

        $revoked_count = UserSession::query()
            ->whereNull('revoked_at')
            ->where('jti', '!=', $current_jti)
            ->update(['revoked_at' => now()]);

        AuditLogger::log(
            'session.revoked_all',
            "Logged out all other active sessions account-wide ({$revoked_count} sessions).",
            $request->user(),
            ['revoked_count' => $revoked_count]
        );

        return response()->json([
            'message' => $revoked_count === 1
                ? '1 session logged out successfully.'
                : "{$revoked_count} sessions logged out successfully.",
            'revoked_count' => $revoked_count,
        ]);
    }
}
