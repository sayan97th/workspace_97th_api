<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
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
 * Always answers with a redirect back into the frontend that says how it went.
 */
class SlackOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, SlackService $slack_service): RedirectResponse
    {
        try {
            $context = $slack_service->consumeState($request->query('state'));
        } catch (SlackException $exception) {
            return $this->redirectToFrontend('/profile', ['section' => 'notifications'], 'error', $exception->error_code);
        }

        $is_install = $context['purpose'] === SlackService::PURPOSE_INSTALL;
        $user = $context['user'];
        $return_path = $context['return_path'];
        $destination = $return_path ?? ($is_install ? '/administration' : '/profile');
        $query = $return_path ? [] : ($is_install ? ['section' => 'integrations'] : ['section' => 'notifications']);

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
                AuditLogger::log('slack.connected', "Connected the Slack workspace \"{$installation->team_name}\".", $user);
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
