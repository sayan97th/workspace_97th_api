<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board's Integrations dialog, modeled on monday.com's integration
 * center: every app (Email, Slack, Gmail, Outlook, Google Calendar) with the caller's connection
 * status, read once for the sidebar badges and the overview cards.
 */
class IntegrationOverviewController extends Controller
{
    /**
     * GET /api/integrations/overview
     */
    public function __invoke(Request $request, IntegrationOverview $overview): JsonResponse
    {
        return response()->json($overview->forUser($request->user()));
    }
}
