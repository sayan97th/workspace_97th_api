<?php

namespace App\Http\Controllers\Admin\Impersonation;

use App\Concerns\IssuesJwtTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\UserSession;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Lets a `super_admin` or `admin` briefly sign in as another account to reproduce and
 * troubleshoot what that person sees, without knowing or resetting their password.
 * Deliberately narrow in scope: {@see store()} mints a short-lived JWT for the target that
 * carries `impersonator_id`/`impersonation_session_id` custom claims on top of the target's
 * normal claims, and {@see stop()} blacklists that token and closes the
 * {@see ImpersonationSession} row it opened. Both ends of every session are written to the
 * account-wide audit trail via {@see AuditLogger}.
 *
 * Route-level `role:super_admin,admin` middleware (see routes/api.php) already keeps `staff`
 * and `client` accounts out of {@see store()} entirely; the checks in here layer the
 * finer-grained "who can impersonate whom" boundary on top of that floor.
 */
class ImpersonationController extends Controller
{
    use IssuesJwtTokens;

    /** Nobody may impersonate an account holding this role, not even another super_admin. */
    private const PROTECTED_ROLE = 'super_admin';

    /** Roles a plain `admin` (i.e. not a `super_admin`) is not allowed to impersonate. */
    private const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    /**
     * Impersonation tokens are intentionally shorter-lived than a normal login and, unlike a
     * normal login, can never be silently refreshed (see `AuthController::refresh()`) — the
     * session simply ends when this window is up, capping how long an admin can act as someone
     * else before having to deliberately start a new, freshly-logged session.
     */
    private const IMPERSONATION_TTL_MINUTES = 30;

    /**
     * POST /api/admin/users/{user}/impersonate
     */
    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Defense in depth: the route's `role:super_admin,admin` middleware already keeps a
        // plain client/staff token out, but if the *current* token is itself an impersonation
        // token (however that happened), refuse to chain a second session on top of it.
        if ($this->guard()->payload()->get('impersonator_id')) {
            return response()->json([
                'message' => 'You are already impersonating a user. Stop that session before starting another.',
            ], 422);
        }

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot impersonate your own account.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is disabled and cannot be impersonated.'], 422);
        }

        if (! $this->actorCanImpersonate($actor, $user)) {
            return response()->json(['message' => 'You do not have permission to impersonate this account.'], 403);
        }

        $session = ImpersonationSession::create([
            'admin_id' => $actor->id,
            'target_user_id' => $user->id,
            'admin_session_jti' => $this->guard()->payload()->get('jti'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'started_at' => now(),
        ]);

        $this->guard()->factory()->setTTL(self::IMPERSONATION_TTL_MINUTES);

        $token = $this->guard()->claims([
            'impersonator_id' => $actor->id,
            'impersonation_session_id' => $session->id,
        ])->login($user);

        $user->load('roles:id,name,display_name');
        $this->recordSession($token, $user, null);

        $session->update(['impersonation_jti' => JWTAuth::setToken($token)->getPayload()->get('jti')]);

        AuditLogger::log(
            'user.impersonation_started',
            "Started impersonating {$user->full_name}.",
            $actor,
            ['target_user_id' => $user->id, 'impersonation_session_id' => $session->id],
        );

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'user' => new ProfileResource($user),
            'impersonation' => [
                'session_id' => $session->id,
                'started_at' => $session->started_at,
                'admin' => [
                    'id' => $actor->id,
                    'full_name' => $actor->full_name,
                    'email' => $actor->email,
                ],
            ],
        ]);
    }

    /**
     * POST /api/impersonation/stop
     *
     * Called while still holding the impersonation token (the target's identity, not the
     * admin's). Blacklists that token and closes the {@see ImpersonationSession} row; restoring
     * the admin's own session is the frontend's job, since this endpoint only ever sees the
     * target's token, never the admin's stashed one.
     */
    public function stop(Request $request): JsonResponse
    {
        $payload = $this->guard()->payload();
        $session_id = $payload->get('impersonation_session_id');
        $admin_id = $payload->get('impersonator_id');

        if (! $session_id || ! $admin_id) {
            return response()->json(['message' => 'You are not currently impersonating a user.'], 422);
        }

        /** @var User $target */
        $target = $request->user();

        $session = ImpersonationSession::where('id', $session_id)
            ->where('admin_id', $admin_id)
            ->where('target_user_id', $target->id)
            ->whereNull('ended_at')
            ->first();

        $session?->update(['ended_at' => now(), 'ended_reason' => 'manual']);

        UserSession::where('jti', $payload->get('jti'))->update(['revoked_at' => now()]);

        AuditLogger::log(
            'user.impersonation_ended',
            "Stopped impersonating {$target->full_name}.",
            User::find($admin_id),
            ['target_user_id' => $target->id, 'impersonation_session_id' => $session_id],
        );

        // Blacklists this specific token so it can't be replayed after the session ends.
        $this->guard()->logout();

        return response()->json(['message' => 'Impersonation session ended.']);
    }

    /**
     * Mirrors {@see \App\Http\Controllers\Admin\User\UserController::actorCanManage()}'s
     * role-hierarchy boundary: a plain `admin` may only impersonate client-tier accounts, while
     * a `super_admin` may impersonate anyone except another `super_admin`.
     */
    private function actorCanImpersonate(User $actor, User $target): bool
    {
        if ($target->hasRole(self::PROTECTED_ROLE)) {
            return false;
        }

        if ($actor->hasRole('super_admin')) {
            return true;
        }

        return $target->roles->pluck('name')->intersect(self::STAFF_ROLES)->isEmpty();
    }
}
