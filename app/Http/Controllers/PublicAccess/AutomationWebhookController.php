<?php

namespace App\Http\Controllers\PublicAccess;

use App\Http\Controllers\Controller;
use App\Models\BoardAutomation;
use App\Services\Board\BoardAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public end of a "When a webhook is received" automation. Any service (a form tool, a
 * payment provider, Zapier, an email parser) posts JSON to `/api/public/automation-webhooks/{token}`
 * and the automation runs with that body, which its actions read through `{payload.*}` tokens.
 * The token is an unguessable random string, replaced from the Manage tab when it leaks.
 */
class AutomationWebhookController extends Controller
{
    /** Largest body accepted, in bytes. */
    private const MAX_BODY_BYTES = 65536;

    /**
     * POST /api/public/automation-webhooks/{token}
     */
    public function receive(Request $request, string $token, BoardAutomationService $automation_service): JsonResponse
    {
        $automation = strlen($token) >= 32
            ? BoardAutomation::where('webhook_token', $token)->where('trigger_type', BoardAutomation::TRIGGER_WEBHOOK_RECEIVED)->first()
            : null;

        if (! $automation) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'The body is too large.'], 413);
        }
        if (! $automation->is_enabled) {
            return response()->json(['message' => 'This automation is turned off, nothing was done.', 'status' => 'disabled'], 202);
        }

        $payload = $request->isJson() ? (array) $request->json()->all() : $request->except([]);
        $automation_service->handleWebhook($automation, $payload);

        return response()->json(['message' => 'Received.', 'status' => 'received'], 202);
    }
}
