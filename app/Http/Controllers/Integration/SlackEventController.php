<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifySlackSignature;
use App\Models\SlackInstallation;
use App\Services\Slack\SlackDiagnosticsService;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/integrations/slack/events
 *
 * The Event Subscriptions request URL of the Slack app. Public, but every request has
 * already passed {@see VerifySlackSignature}, so it is known to come
 * from Slack. Answers the one time `url_verification` challenge Slack sends when the URL is
 * saved, and forgets the installation when the app is removed from the workspace, so the UI
 * stops claiming Slack is connected.
 */
class SlackEventController extends Controller
{
    private const REVOKING_EVENTS = ['app_uninstalled', 'tokens_revoked'];

    public function __invoke(Request $request, SlackService $slack_service, SlackDiagnosticsService $diagnostics_service): JsonResponse
    {
        $type = (string) $request->input('type');
        $event_type = (string) $request->input('event.type');

        $diagnostics_service->recordSignedRequest($event_type !== '' ? $event_type : $type);

        if ($type === 'url_verification') {
            return response()->json(['challenge' => (string) $request->input('challenge')]);
        }

        if ($type === 'event_callback' && in_array($event_type, self::REVOKING_EVENTS, true)) {
            $installation = SlackInstallation::current();

            if ($installation && $installation->team_id === $request->input('team_id')) {
                $team_name = $installation->team_name;
                $slack_service->handleRevokedInstallation($installation);
                AuditLogger::log('slack.uninstalled', "The Slack app was removed from the \"{$team_name}\" workspace.");
            }
        }

        // Slack only needs a fast 200, anything else makes it retry the delivery.
        return response()->json(['ok' => true]);
    }
}
