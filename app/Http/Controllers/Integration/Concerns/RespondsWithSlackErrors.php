<?php

namespace App\Http\Controllers\Integration\Concerns;

use App\Services\Slack\SlackException;
use Illuminate\Http\JsonResponse;

/**
 * Turns a {@see SlackException} into a message a person can act on, shared by every Slack controller.
 */
trait RespondsWithSlackErrors
{
    protected function errorResponse(SlackException $exception): JsonResponse
    {
        $message = match ($exception->error_code) {
            'not_configured' => 'Slack is not configured yet. Ask an administrator to add the Slack app credentials in Administration > Integrations.',
            'client_secret_required' => 'Enter the client secret of this Slack app.',
            'not_installed' => 'Slack is not connected to this account yet.',
            'not_linked' => 'Connect your Slack account first.',
            'recipient_not_linked' => 'That member has not linked their Slack account yet. Ask them to use "Connect my Slack" first.',
            'ratelimited' => 'Slack is busy right now. Please try again in a moment.',
            'connection_failed' => 'Slack could not be reached. Please try again in a moment.',
            'channel_not_found', 'user_not_found' => 'Slack could not find where to send that message.',
            'not_in_channel' => 'The Slack app is not a member of that channel. Invite it to the channel first.',
            'is_archived' => 'That Slack channel is archived.',
            'missing_scope' => 'The Slack app is missing a permission. Use "Reconnect" on the workspace in Administration > Integrations to grant it.',
            default => $exception->isTokenInvalid()
                ? 'The Slack connection is no longer valid. Reconnect the workspace from Administration > Integrations.'
                : "Slack returned an error ({$exception->error_code}).",
        };

        $status = in_array($exception->error_code, ['not_configured', 'connection_failed', 'ratelimited'], true) ? 503 : 422;

        return response()->json(['message' => $message], $status);
    }
}
