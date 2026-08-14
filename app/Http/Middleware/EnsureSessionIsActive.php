<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureSessionIsActive
{
    /**
     * Reject requests made with a token whose {@see UserSession} row has been revoked
     * (the user clicked "Log out" on that device from Session history). Tokens issued
     * before this feature shipped have no matching row, and requests authenticated
     * without a real parseable JWT (e.g. `actingAs()` in tests) have no payload at all —
     * both cases fail open rather than locking out requests this middleware can't reason
     * about.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $guard = Auth::guard('api');
            $jti = $guard->check() ? $guard->payload()->get('jti') : null;
        } catch (Throwable) {
            $jti = null;
        }

        if ($jti) {
            $session = UserSession::where('jti', $jti)->first();

            if ($session && $session->revoked_at !== null) {
                return response()->json(['message' => 'This session has been logged out. Please sign in again.'], 401);
            }
        }

        return $next($request);
    }
}
