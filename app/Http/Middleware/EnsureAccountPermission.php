<?php

namespace App\Http\Middleware;

use App\Support\AccountPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for {@see AccountPermissions}, e.g. `account.permission:export_data`.
 */
class EnsureAccountPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user) {
            AccountPermissions::authorize($user, $permission);
        }

        return $next($request);
    }
}
