<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Models\BoardDoc;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\JsonResponse;

/**
 * A Doc opened through its public link (the Share dialog's "Share public
 * link"). Read only, and only the doc itself: its title, the creator row and
 * the body. Comments, the people with access and the workspace stay private.
 */
class PublicSharedDocController extends Controller
{
    /**
     * GET /api/public/docs/{token}
     */
    public function show(string $token): JsonResponse
    {
        $doc = BoardDoc::query()
            ->where('public_token', $token)
            ->where('is_public_link_enabled', true)
            ->first();

        /** @var WorkspaceNavigationItem|null $board */
        $board = $doc?->board()->with('creator')->first();

        abort_if(! $doc || ! $board || $board->is_archived, 404, 'This link is no longer available.');

        return response()->json([
            'label' => $board->label,
            'content' => $doc->content,
            'created_at' => $board->created_at?->toIso8601String(),
            'updated_at' => ($doc->updated_at ?? $board->updated_at)?->toIso8601String(),
            'creator' => $board->creator ? [
                'full_name' => $board->creator->full_name,
                'profile_photo_url' => $board->creator->profile_photo_url,
            ] : null,
        ]);
    }
}
