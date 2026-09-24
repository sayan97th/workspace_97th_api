<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\SlackChannelTestRequest;
use App\Http\Requests\Integration\SlackConnectRequest;
use App\Models\BoardAutomation;
use App\Models\SlackInstallation;
use App\Services\Slack\SlackClient;
use App\Services\Slack\SlackDiagnosticsService;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackNotifier;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackIntegrationController extends Controller
{
    public function __construct(private readonly SlackService $slack_service) {}

    /**
     * GET /api/integrations/slack
     *
     * What the UI needs to decide what to render: whether the server has Slack credentials,
     * whether a workspace is connected, and whether the caller linked their own account.
     * Open to every authenticated user, the top of the profile's Notifications page and the
     * automations picker both need it, and it never includes the bot token.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->statusPayload($request));
    }

    /**
     * POST /api/integrations/slack/install-url  (admin, super_admin)
     *
     * Returns the "Add to Slack" URL instead of redirecting, the browser cannot attach a
     * JWT to a top level navigation, so the frontend fetches this and then navigates.
     */
    public function installUrl(SlackConnectRequest $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->slack_service->buildInstallUrl($request->user(), $request->validated('return_path'))]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * DELETE /api/integrations/slack  (admin, super_admin)
     *
     * Revokes the bot token, removes every member link and switches off every automation
     * that posts to Slack, so none of them keeps failing silently.
     */
    public function destroy(Request $request): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['message' => 'Slack is not connected.'], 404);
        }

        $team_name = $installation->team_name;
        $this->slack_service->disconnect($installation);

        BoardAutomation::whereIn('action_type', BoardAutomation::slackActions())->update(['is_enabled' => false]);

        AuditLogger::log('slack.disconnected', "Disconnected the Slack workspace \"{$team_name}\".", $request->user());

        return response()->json([
            'message' => 'Slack disconnected successfully.',
            ...$this->statusPayload($request),
        ]);
    }

    /**
     * GET /api/integrations/slack/channels
     *
     * Channels the app can post to, for the automation builder's channel picker.
     */
    public function channels(): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $this->slack_service->listChannels($installation)]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * POST /api/integrations/slack/link-url
     *
     * The "Sign in with Slack" URL that proves which Slack account belongs to the caller.
     */
    public function linkUrl(SlackConnectRequest $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->slack_service->buildLinkUrl($request->user(), $request->validated('return_path'))]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * DELETE /api/integrations/slack/link
     *
     * Stops Slack notifications for the caller only.
     */
    public function unlink(Request $request): JsonResponse
    {
        $this->slack_service->unlinkUser($request->user());

        return response()->json([
            'message' => 'Your Slack account was disconnected.',
            ...$this->statusPayload($request),
        ]);
    }

    /**
     * POST /api/integrations/slack/link/test
     *
     * Sends the caller a direct message right away, so a broken setup shows its real error.
     */
    public function sendTest(Request $request, SlackNotifier $slack_notifier, SlackClient $slack_client): JsonResponse
    {
        try {
            $slack_notifier->sendTestToUser($request->user()->load('slackLink.installation'), $slack_client);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['message' => 'Test message sent. Check your Slack.']);
    }

    /**
     * GET /api/integrations/slack/diagnostics  (admin, super_admin)
     *
     * Runs every Slack check live and reports each one on its own, for the /admin/test/slack page.
     * Never includes a secret, only the public client id and the URLs to register in Slack.
     */
    public function diagnostics(Request $request, SlackDiagnosticsService $diagnostics_service): JsonResponse
    {
        return response()->json($diagnostics_service->run($request->user()));
    }

    /**
     * POST /api/integrations/slack/diagnostics/channel-test  (admin, super_admin)
     *
     * Posts a test message to the chosen channel right away, so a broken setup shows its real error.
     */
    public function sendChannelTest(SlackChannelTestRequest $request, SlackDiagnosticsService $diagnostics_service): JsonResponse
    {
        try {
            $diagnostics_service->sendChannelTest($request->validated('channel_id'), $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['message' => 'Test message posted. Check the channel in Slack.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(Request $request): array
    {
        $user = $request->user();
        $installation = SlackInstallation::current();
        $link = $installation ? $user->slackLink()->where('slack_installation_id', $installation->id)->first() : null;

        return [
            'is_configured' => $this->slack_service->isConfigured(),
            'is_connected' => $installation !== null,
            'can_manage' => $user->hasRole(['super_admin', 'admin']),
            'workspace' => $installation ? [
                'team_id' => $installation->team_id,
                'team_name' => $installation->team_name,
                'connected_at' => $installation->created_at,
                'connected_by' => $installation->installedBy?->full_name,
                'linked_members_count' => $installation->userLinks()->count(),
            ] : null,
            'current_user_link' => $link ? [
                'slack_user_id' => $link->slack_user_id,
                'slack_display_name' => $link->slack_display_name,
                'linked_at' => $link->linked_at,
            ] : null,
        ];
    }

    private function errorResponse(SlackException $exception): JsonResponse
    {
        $message = match ($exception->error_code) {
            'not_configured' => 'Slack is not configured on this server yet. Ask an administrator to add the Slack app credentials.',
            'not_installed' => 'Slack is not connected to this account yet.',
            'not_linked' => 'Connect your Slack account first.',
            'ratelimited' => 'Slack is busy right now. Please try again in a moment.',
            'connection_failed' => 'Slack could not be reached. Please try again in a moment.',
            'channel_not_found', 'user_not_found' => 'Slack could not find where to send that message.',
            'not_in_channel' => 'The Slack app is not a member of that channel. Invite it to the channel first.',
            'is_archived' => 'That Slack channel is archived.',
            'missing_scope' => 'The Slack app is missing a permission. Reconnect Slack from Administration.',
            default => $exception->isTokenInvalid()
                ? 'The Slack connection is no longer valid. Reconnect Slack from Administration.'
                : "Slack returned an error ({$exception->error_code}).",
        };

        $status = in_array($exception->error_code, ['not_configured', 'connection_failed', 'ratelimited'], true) ? 503 : 422;

        return response()->json(['message' => $message], $status);
    }
}
