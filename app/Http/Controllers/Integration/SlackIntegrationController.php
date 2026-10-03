<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackChannelIndexRequest;
use App\Http\Requests\Integration\SlackChannelTestRequest;
use App\Http\Requests\Integration\SlackConnectRequest;
use App\Http\Requests\Integration\SlackUserTestRequest;
use App\Models\SlackInstallation;
use App\Models\User;
use App\Services\Slack\SlackClient;
use App\Services\Slack\SlackDiagnosticsService;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackNotifier;
use App\Services\Slack\SlackService;
use App\Services\Slack\SlackStatus;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackIntegrationController extends Controller
{
    use RespondsWithSlackErrors;

    public function __construct(
        private readonly SlackService $slack_service,
        private readonly SlackStatus $slack_status,
    ) {}

    /**
     * GET /api/integrations/slack
     *
     * What the UI needs to decide what to render: whether Slack credentials are set, which
     * workspace is active, and whether the caller linked their own account in it.
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
     * JWT to a top level navigation, so the frontend fetches this and opens it in a new tab.
     * The same URL adds another workspace or reconnects an existing one, Slack lets the
     * administrator pick the workspace in the top right corner of its page.
     */
    public function installUrl(SlackConnectRequest $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->slack_service->buildInstallUrl($request->user(), $request->validated('return_path'), $request->display())]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * GET /api/integrations/slack/channels
     *
     * Channels the app can post to, for the automation builder's channel picker. Every
     * public channel, plus the private channels the app was invited to. `?refresh=1`
     * reads them from Slack again instead of the short lived cache.
     */
    public function channels(SlackChannelIndexRequest $request): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $this->slack_service->listChannels($installation, $request->wantsFreshChannels())]);
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
            return response()->json(['url' => $this->slack_service->buildLinkUrl($request->user(), $request->validated('return_path'), $request->display())]);
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
     * GET /api/integrations/slack/diagnostics/recipients  (admin, super_admin)
     *
     * Members who can receive a Slack direct message, for the notification test picker.
     */
    public function notificationRecipients(SlackDiagnosticsService $diagnostics_service): JsonResponse
    {
        return response()->json(['data' => $diagnostics_service->listNotificationRecipients()]);
    }

    /**
     * POST /api/integrations/slack/diagnostics/user-test  (admin, super_admin)
     *
     * Sends a custom notification to another member as a Slack direct message right away, so
     * an administrator can confirm a specific person really receives Slack notifications.
     */
    public function sendUserTest(SlackUserTestRequest $request, SlackDiagnosticsService $diagnostics_service): JsonResponse
    {
        $actor = $request->user();
        $recipient = User::findOrFail($request->validated('user_id'));

        try {
            $diagnostics_service->sendUserTest($recipient, $request->validated('message'), $actor);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        AuditLogger::log('slack.test_notification_sent', "Sent a Slack test notification to {$recipient->full_name}.", $actor, [
            'recipient_id' => $recipient->id,
        ]);

        return response()->json(['message' => "Notification sent to {$recipient->full_name}. Ask them to check Slack."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(Request $request): array
    {
        return $this->slack_status->forUser($request->user());
    }
}
