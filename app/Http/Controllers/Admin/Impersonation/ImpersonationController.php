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
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Lets a `super_admin` briefly sign in as another account to reproduce and troubleshoot what
 * that person sees, without knowing or resetting their password. Deliberately narrow in scope:
 * {@see store()} mints a short lived JWT for the target that carries
 * `impersonator_id`/`impersonation_session_id` custom claims on top of the target's normal
 * claims, and {@see stop()} blacklists that token and closes the {@see ImpersonationSession}
 * row it opened. Both ends of every session are written to the account wide audit trail via
 * {@see AuditLogger}.
 *
 * Route level `role:super_admin` middleware (see routes/api.php) already keeps every other role
 * out of {@see store()}, the checks in here repeat that and add the "who can be impersonated"
 * boundary on top.
 */
class ImpersonationController extends Controller
{
    use IssuesJwtTokens;

    /** The only role allowed to impersonate, and also the one role nobody may impersonate. */
    private const IMPERSONATOR_ROLE = 'super_admin';

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

        // Defense in depth: the route's `role:super_admin` middleware already keeps every other
        // role out, but if the *current* token is itself an impersonation
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

        $admin_session_jti = $this->guard()->payload()->get('jti');

        $this->closeStaleSessions($actor);

        // The whole impersonation lives inside one transaction so a failure while minting the
        // token or recording the session never leaves an orphaned, never-ending session row.
        [$session, $token, $payload] = DB::transaction(function () use ($actor, $user, $request, $admin_session_jti) {
            $session = ImpersonationSession::create([
                'admin_id' => $actor->id,
                'target_user_id' => $user->id,
                'admin_session_jti' => $admin_session_jti,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'started_at' => now(),
            ]);

            $token = $this->issueImpersonationToken($actor, $user, $session);
            $payload = JWTAuth::setToken($token)->getPayload();

            $session->update(['impersonation_jti' => $payload->get('jti')]);

            return [$session, $token, $payload];
        });

        $user->load('roles:id,name,display_name');

        // Listed in the target's Session history like any other device, so the impersonation
        // token can also be revoked from there and is rejected by `EnsureSessionIsActive`.
        $session_expires_at = $this->sessionExpiresAt($payload);
        $this->recordSession($payload, $session_expires_at, $user, null);

        AuditLogger::log(
            'user.impersonation_started',
            "Started impersonating {$user->full_name}.",
            $actor,
            ['target_user_id' => $user->id, 'impersonation_session_id' => $session->id],
        );

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => self::IMPERSONATION_TTL_MINUTES * 60,
            'remember' => false,
            'session_expires_at' => $session_expires_at->toIso8601String(),
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
            is_numeric($admin_id) ? User::find((int) $admin_id) : null,
            ['target_user_id' => $target->id, 'impersonation_session_id' => $session_id],
        );

        // Blacklists this specific token so it can't be replayed after the session ends.
        $this->guard()->logout();

        return response()->json(['message' => 'Impersonation session ended.']);
    }

    /**
     * An impersonation token that simply ran out (or a tab that was closed mid session) never
     * reaches {@see stop()}, so its row would stay open forever. Any session this admin still has
     * open when starting a new one is by definition abandoned, since chaining is refused above.
     */
    private function closeStaleSessions(User $actor): void
    {
        ImpersonationSession::where('admin_id', $actor->id)
            ->whereNull('ended_at')
            ->get()
            ->each(function (ImpersonationSession $stale_session) {
                $expires_at = $stale_session->started_at->copy()->addMinutes(self::IMPERSONATION_TTL_MINUTES);
                $is_expired = $expires_at->isPast();

                $stale_session->update([
                    'ended_at' => $is_expired ? $expires_at : now(),
                    'ended_reason' => $is_expired ? 'expired' : 'superseded',
                ]);

                if ($stale_session->impersonation_jti) {
                    UserSession::where('jti', $stale_session->impersonation_jti)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);
                }
            });
    }

    /**
     * Mints the target's short lived impersonation token. It carries the usual
     * `session_expires_at`/`remember` claims (see {@see IssuesJwtTokens::issueToken()}) capped at
     * the impersonation window, plus the `impersonator_id`/`impersonation_session_id` claims
     * that {@see stop()} and `AuthController::refresh()` rely on.
     */
    private function issueImpersonationToken(User $actor, User $target, ImpersonationSession $session): string
    {
        $this->guard()->factory()->setTTL(self::IMPERSONATION_TTL_MINUTES);

        return $this->guard()
            ->claims([
                'remember' => false,
                'session_expires_at' => now()->addMinutes(self::IMPERSONATION_TTL_MINUTES)->timestamp,
                'impersonator_id' => $actor->id,
                'impersonation_session_id' => $session->id,
            ])
            ->login($target);
    }

    /**
     * Only a `super_admin` may impersonate, and never another `super_admin`. Repeats the route
     * middleware on purpose so the rule holds even if the route is ever regrouped.
     */
    private function actorCanImpersonate(User $actor, User $target): bool
    {
        return $actor->hasRole(self::IMPERSONATOR_ROLE) && ! $target->hasRole(self::IMPERSONATOR_ROLE);
    }
}
