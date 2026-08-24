<?php

namespace App\Http\Middleware;

use App\Models\AccountSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When two-factor authentication is required account-wide (Administration > Authentication),
 * blocks every authenticated request from a user who hasn't confirmed 2FA yet, except the
 * handful of endpoints they need to actually set it up (and `me`/`logout`). Login itself
 * still succeeds either way, this middleware is what turns "logged in" into "locked to just
 * the 2FA setup flow" for a non-compliant account, since blocking login outright would leave
 * them with no way to ever reach the setup endpoints in the first place.
 */
class EnsureTwoFactorSetupWhenEnforced
{
    /** Path fragments (matched via `Request::is()`) a non-compliant user can still reach. */
    private const ALLOWED_PATHS = [
        'api/auth/two-factor*',
        'api/auth/me',
        'api/auth/logout',
        'api/auth/refresh',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if (! AccountSetting::current()->two_factor_enforced) {
            return $next($request);
        }

        foreach (self::ALLOWED_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This account requires two-factor authentication. Set it up to continue.',
            'code' => 'two_factor_setup_required',
        ], 428);
    }
}
