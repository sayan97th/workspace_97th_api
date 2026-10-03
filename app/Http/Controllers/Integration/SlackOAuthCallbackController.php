<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\SlackInstallation;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/integrations/slack/callback
 *
 * The one redirect URI both Slack OAuth flows return to. Public on purpose, the browser
 * arrives from Slack with no JWT, so who the request is for comes only from the single use
 * `state` value issued when the flow started, see {@see SlackService::consumeState()}.
 *
 * Always answers with a redirect back into the frontend that says how it went. A flow opened
 * in its own tab lands on `/integrations/slack/complete`, which tells the tab that started it
 * and closes itself, a flow that took over the page goes back to where it started.
 */
class SlackOAuthCallbackController extends Controller
{
    private const TAB_COMPLETE_PATH = '/integrations/slack/complete';

    public function __invoke(Request $request, SlackService $slack_service): RedirectResponse
    {
        try {
            $context = $slack_service->consumeState($request->query('state'));
        } catch (SlackException $exception) {
            // Without a valid state the display mode is unknown, the completion page handles both.
            return $this->redirectToFrontend(self::TAB_COMPLETE_PATH, [], 'error', $exception->error_code);
        }

        $is_install = $context['purpose'] === SlackService::PURPOSE_INSTALL;
        $user = $context['user'];

        if ($context['display'] === SlackService::DISPLAY_TAB) {
            $destination = self::TAB_COMPLETE_PATH;
            $query = ['purpose' => $context['purpose']];
        } else {
            $return_path = $context['return_path'];
            $destination = $return_path ?? ($is_install ? '/administration' : '/profile');
            $query = $return_path ? [] : ($is_install ? ['section' => 'integrations'] : ['section' => 'notifications']);
        }

        $code = $request->query('code');
        if ($request->query('error') || ! is_string($code) || $code === '') {
            return $this->redirectToFrontend($destination, $query, 'error', 'access_denied');
        }

        try {
            if ($is_install) {
                if (! $user->hasRole(['super_admin', 'admin'])) {
                    return $this->redirectToFrontend($destination, $query, 'error', 'forbidden');
                }

                $installation = $slack_service->completeInstall($code, $user);
                AuditLogger::log('slack.connected', "Connected the Slack workspace \"{$installation->team_name}\".", $user, [
                    'team_id' => $installation->team_id,
                ]);

                $matched_count = $this->matchMembers($slack_service, $installation);

                // The completion page says which workspace was added, a page return keeps its URL clean.
                if ($context['display'] === SlackService::DISPLAY_TAB) {
                    $query['workspace'] = $installation->team_name;
                    if ($matched_count !== null) {
                        $query['matched'] = (string) $matched_count;
                    }
                }
            } else {
                $slack_service->completeLink($code, $user);
            }
        } catch (SlackException $exception) {
            Log::warning('Slack OAuth callback failed', ['purpose' => $context['purpose'], 'error' => $exception->error_code]);

            return $this->redirectToFrontend($destination, $query, 'error', $exception->error_code);
        }

        return $this->redirectToFrontend($destination, $query, 'connected');
    }

    /**
     * Links members to their Slack account by email right after an install, so notifications
     * reach them without each one signing in to Slack. A failure here never fails the install,
     * the administrator can run the match again from Administration. Returns how many members
     * were matched, or null when matching was not possible.
     */
    private function matchMembers(SlackService $slack_service, SlackInstallation $installation): ?int
    {
        try {
            return $slack_service->matchMembersByEmail($installation)['matched'];
        } catch (SlackException $exception) {
            Log::info('Slack members could not be matched by email after install', ['error' => $exception->error_code]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function redirectToFrontend(string $path, array $query, string $result, ?string $reason = null): RedirectResponse
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');
        $query = [...$query, 'slack' => $result];

        if ($reason) {
            $query['reason'] = $reason;
        }

        // `$path` may already carry a query string, for example the board's `?integrate=slack`.
        $separator = str_contains($path, '?') ? '&' : '?';

        return redirect("{$frontend_url}{$path}{$separator}".http_build_query($query));
    }
}
