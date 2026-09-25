<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Profile\UserSessionController;
use App\Http\Resources\AdminUserSessionResource;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Admin\AdminUserQuery;
use App\Support\AuditLogger;
use App\Support\UserAgentParser;
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
    private const SORT_FIELDS = ['last_used_at', 'created_at', 'user'];

    private const DEVICE_TYPES = ['desktop', 'mobile', 'tablet'];

    private const BROWSER_PATTERNS = [
        'chrome' => "user_agent LIKE '%Chrome/%' AND user_agent NOT LIKE '%Edg/%' AND user_agent NOT LIKE '%OPR/%'",
        'firefox' => "user_agent LIKE '%Firefox/%'",
        'safari' => "user_agent LIKE '%Safari/%' AND user_agent NOT LIKE '%Chrome/%' AND user_agent NOT LIKE '%Chromium/%'",
        'edge' => "user_agent LIKE '%Edg/%'",
        'opera' => "(user_agent LIKE '%OPR/%' OR user_agent LIKE '%Opera%')",
    ];

    /**
     * GET /api/admin/sessions
     *
     * Filters: `search` (name or email), `user` (user ids), `device_type`
     * (desktop, mobile, tablet), `browser` (chrome, firefox, safari, edge, opera),
     * `last_used_from` / `last_used_to` (Y-m-d). Every list filter takes a comma separated
     * list, any of. Sort with `sort_field` (last_used_at, created_at, user) and
     * `sort_direction`.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $sort_field = in_array($request->query('sort_field'), self::SORT_FIELDS, true) ? $request->query('sort_field') : 'last_used_at';
        $sort_direction = $request->query('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query = UserSession::with('user:id,first_name,last_name,email,profile_photo_path')
            ->select('user_sessions.*')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now());

        if ($search !== '') {
            $query->whereHas('user', function ($user_query) use ($search) {
                $user_query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $user_ids = array_map('intval', array_filter(AdminUserQuery::listParam($request, 'user'), 'ctype_digit'));
        if ($user_ids !== []) {
            $query->whereIn('user_id', $user_ids);
        }

        $device_types = AdminUserQuery::listParam($request, 'device_type', self::DEVICE_TYPES);
        if ($device_types !== [] && count($device_types) < count(self::DEVICE_TYPES)) {
            $conditions = UserAgentParser::deviceTypeConditions();
            $query->where(function ($q) use ($device_types, $conditions) {
                foreach ($device_types as $device_type) {
                    $q->orWhereRaw(match ($device_type) {
                        'tablet' => $conditions['tablet'],
                        'mobile' => $conditions['mobile'],
                        default => "(user_agent IS NULL OR (NOT {$conditions['tablet']} AND NOT {$conditions['mobile']}))",
                    });
                }
            });
        }

        $browsers = AdminUserQuery::listParam($request, 'browser', array_keys(self::BROWSER_PATTERNS));
        if ($browsers !== []) {
            $query->where(function ($q) use ($browsers) {
                foreach ($browsers as $browser) {
                    $q->orWhereRaw('('.self::BROWSER_PATTERNS[$browser].')');
                }
            });
        }

        if ($from = AdminUserQuery::dateParam($request, 'last_used_from')) {
            $query->where('last_used_at', '>=', $from->startOfDay());
        }
        if ($to = AdminUserQuery::dateParam($request, 'last_used_to')) {
            $query->where('last_used_at', '<=', $to->endOfDay());
        }

        if ($sort_field === 'user') {
            $query->orderBy(
                User::query()->select('first_name')->whereColumn('users.id', 'user_sessions.user_id'),
                $sort_direction,
            );
        } else {
            $query->orderBy("user_sessions.{$sort_field}", $sort_direction);
        }
        $query->orderBy('user_sessions.id', $sort_direction);

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
     * DELETE /api/admin/sessions/users/{user}
     *
     * "Log out this user everywhere": revokes every active session one person has, except
     * the caller's own current device when an admin targets themselves.
     */
    public function destroyForUser(Request $request, User $user): JsonResponse
    {
        // No token payload (e.g. a non JWT request) simply means there is no device to spare.
        $current_jti = rescue(fn () => Auth::guard('api')->payload()->get('jti'), null, false);

        $revoked_count = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($current_jti, fn ($query) => $query->where('jti', '!=', $current_jti))
            ->update(['revoked_at' => now()]);

        AuditLogger::log(
            'session.revoked_user',
            "Logged out {$user->full_name} everywhere ({$revoked_count} sessions).",
            $request->user(),
            ['target_user_id' => $user->id, 'revoked_count' => $revoked_count]
        );

        return response()->json([
            'message' => $revoked_count === 1
                ? "1 session of {$user->full_name} logged out."
                : "{$revoked_count} sessions of {$user->full_name} logged out.",
            'revoked_count' => $revoked_count,
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
