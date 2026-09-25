<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Services\Board\SharedViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A board view opened through its public "Share view" link.
 */
class PublicSharedViewController extends Controller
{
    public function __construct(private readonly SharedViewService $shared_views) {}

    /**
     * POST /api/public/views/{token}
     *
     * POST rather than GET so a password never ends up in a url or an access
     * log. Without the right password the response is a 401 that tells the
     * page to ask for one.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $link = $this->shared_views->findLink($token);
        $password = $request->input('password');

        if (! $this->shared_views->passwordMatches($link, is_string($password) ? $password : null)) {
            return response()->json([
                'message' => $password ? 'That password is not correct.' : 'This view is password protected.',
                'requires_password' => true,
                'board' => ['label' => $link->board->label],
            ], 401);
        }

        return response()->json($this->shared_views->payload($link));
    }
}
