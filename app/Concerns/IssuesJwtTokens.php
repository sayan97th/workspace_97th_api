<?php

namespace App\Concerns;

use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserAgentParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPOpenSourceSaver\JWTAuth\Payload;

trait IssuesJwtTokens
{
    /**
     * Signs the user in and returns a fresh access token for a brand new session.
     *
     * Every session gets an absolute end date, stored in the `session_expires_at`
     * claim and carried over on each refresh (see `jwt.persistent_claims`): one day
     * for a normal sign in, or 30 days when the user ticked "Keep me logged in".
     * Access tokens stay short lived and are silently refreshed until that date.
     */
    protected function issueToken(User $user, bool $remember = false): string
    {
        $lifetime_minutes = (int) config($remember ? 'jwt.remember_session_lifetime' : 'jwt.session_lifetime');

        return $this->guard()
            ->claims([
                'remember' => $remember,
                'session_expires_at' => now()->addMinutes($lifetime_minutes)->timestamp,
            ])
            ->login($user);
    }

    /**
     * When the session behind the given token ends for good. Tokens issued before
     * session lifetimes existed have no claim, so they get a normal session
     * counted from their original sign in (`iat` is kept across refreshes).
     */
    protected function sessionExpiresAt(Payload $payload): Carbon
    {
        $session_expires_at = $payload->get('session_expires_at');

        if (is_numeric($session_expires_at)) {
            return Carbon::createFromTimestamp((int) $session_expires_at);
        }

        return Carbon::createFromTimestamp((int) $payload->get('iat'))
            ->addMinutes((int) config('jwt.session_lifetime'));
    }

    /**
     * Builds the auth response and records/rotates the {@see UserSession} row for
     * Session history. Pass `$previous_jti` on token refresh (the jti of the token
     * being replaced) so the refresh rotates the existing session row in place
     * instead of spawning a new "device" every ~55 minutes.
     */
    protected function respondWithToken(string $token, User $user, ?string $previous_jti = null): JsonResponse
    {
        $user->load('roles:id,name,display_name');

        $payload = JWTAuth::setToken($token)->getPayload();
        $session_expires_at = $this->sessionExpiresAt($payload);

        $this->recordSession($payload, $session_expires_at, $user, $previous_jti);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'remember' => (bool) $payload->get('remember'),
            'session_expires_at' => $session_expires_at->toIso8601String(),
            'user' => new ProfileResource($user),
        ]);
    }

    protected function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return Auth::guard('api');
    }

    private function recordSession(Payload $payload, Carbon $session_expires_at, User $user, ?string $previous_jti): void
    {
        $request = request();

        UserSession::updateOrCreate(
            ['jti' => $previous_jti ?? $payload->get('jti')],
            [
                'user_id' => $user->id,
                'jti' => $payload->get('jti'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_label' => UserAgentParser::parse($request->userAgent()),
                'last_used_at' => now(),
                // The whole session's end, not the short lived access token's, so
                // Session history shows how long this device really stays signed in.
                'expires_at' => $session_expires_at,
                'revoked_at' => null,
            ],
        );
    }
}
