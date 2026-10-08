<?php

namespace App\Services\Slack;

/**
 * Turns a {@see SlackException} into a sentence a person can act on. Shared by the Slack
 * controllers, which answer with it, and by the notification test suite, which reports it
 * as the reason a test failed.
 */
class SlackErrorMessage
{
    public static function describe(SlackException $exception): string
    {
        return match ($exception->error_code) {
            'not_configured' => 'Slack is not set up yet. The account owner sets up the Slack app once in Administration > Integrations.',
            'client_secret_required' => 'Enter the client secret of this Slack app.',
            'not_installed' => 'Slack is not connected to this account yet.',
            'not_linked' => 'Connect your Slack account first.',
            'link_requires_https' => 'Slack only allows "Connect my Slack" through an HTTPS redirect URL, and this site uses a plain http one. An administrator can link members with "Match members by email" in Administration > Integrations, or set an HTTPS redirect URL (for local testing, an HTTPS tunnel) in the Slack app settings.',
            'recipient_not_linked' => 'That member has not linked their Slack account yet. Ask them to use "Connect my Slack" first.',
            'ratelimited' => 'Slack is busy right now. Please try again in a moment.',
            'connection_failed' => 'Slack could not be reached. Please try again in a moment.',
            'channel_not_found', 'user_not_found' => 'Slack could not find where to send that message.',
            'not_in_channel' => 'The Slack app is not a member of that channel. Invite it to the channel first.',
            'user_not_in_channel' => 'The recipient is not a member of that channel. Add them to it in Slack or pick another channel.',
            'users_not_found' => 'No Slack member uses that email address.',
            'is_archived' => 'That Slack channel is archived.',
            'missing_scope' => 'The Slack app is missing a permission. Use "Reconnect" on the workspace in Administration > Integrations to grant it.',
            'time_in_past', 'time_too_far' => 'Slack did not accept the time the message was scheduled for.',
            'cant_update_message', 'message_not_found' => 'Slack did not let the app edit that message.',
            'upload_failed' => $exception->getMessage(),
            default => $exception->isTokenInvalid()
                ? 'The Slack connection is no longer valid. Reconnect the workspace from Administration > Integrations.'
                : "Slack returned an error ({$exception->error_code}).",
        };
    }
}
