<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceNavigationItem;
use App\Support\VisibleBoards;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every board scoped endpoint (`boards/{item}`, the sidebar's `navigation/{item}`,
 * `favorites/{item}`, ...) for a private board the caller was not added to, so a direct link
 * or a hand written API call cannot open what the sidebar hides. See {@see VisibleBoards}.
 *
 * Reads the bound `item` and `board` route parameters, so it must run after route model
 * binding, which the `api` group already does before any route middleware.
 */
class EnsureBoardIsVisible
{
    /** Route parameters that carry a navigation item (board, doc or dashboard). */
    private const BOARD_PARAMETERS = ['item', 'board'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || $route === null) {
            return $next($request);
        }

        foreach (self::BOARD_PARAMETERS as $parameter_name) {
            $board = $route->parameter($parameter_name);

            if ($board instanceof WorkspaceNavigationItem && ! VisibleBoards::canView($user, $board)) {
                return response()->json([
                    'message' => 'This board is private. Only the people who were added to it can open it.',
                    'code' => 'board_private',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
