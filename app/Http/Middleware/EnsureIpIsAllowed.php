<?php

namespace App\Http\Middleware;

use App\Models\AccountSetting;
use App\Support\IpRangeMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When IP restriction is enabled (Administration > Authentication), blocks every
 * authenticated request from an IP address not in the allowed ranges. `super_admin`/`admin`
 * are always exempt, the same tier allowed to change this setting in the first place, so
 * turning it on with an empty or wrong range can never lock every admin out of the account
 * that just enabled it.
 */
class EnsureIpIsAllowed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->hasRole(['super_admin', 'admin'])) {
            return $next($request);
        }

        $settings = AccountSetting::current();
        if (! $settings->ip_restriction_enabled) {
            return $next($request);
        }

        $ip = $request->ip();
        if ($ip !== null && IpRangeMatcher::matchesAny($ip, $settings->ip_ranges ?? [])) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Access from your current network is not allowed for this account.',
        ], 403);
    }
}
