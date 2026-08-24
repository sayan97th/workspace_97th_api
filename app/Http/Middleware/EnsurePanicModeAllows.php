<?php

namespace App\Http\Middleware;

use App\Models\AccountSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When panic mode is active, blocks every authenticated request from anyone who isn't a
 * `super_admin`/`admin` — everyone else needs an admin to deactivate it before they can do
 * anything again. Login itself stays reachable (it's outside the authenticated route group),
 * so a signed-out user can always get back in; whether they can do anything once in is what
 * this middleware decides.
 */
class EnsurePanicModeAllows
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! AccountSetting::current()->panic_mode_active || $user->hasRole(['super_admin', 'admin'])) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This account is locked in panic mode. An administrator must deactivate it before you can continue.',
        ], 423);
    }
}
