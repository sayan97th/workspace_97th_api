<?php

namespace App\Http\Controllers\Integration;

use App\Enums\SlackNotificationTest;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackChannelIndexRequest;
use App\Http\Requests\Integration\SlackNotificationTestRequest;
use App\Models\SlackInstallation;
use App\Models\User;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackNotificationTestRunner;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * The Slack notification test suite of /admin/test/slack/notifications (admin, super_admin).
 * Lists every test and runs them one at a time, so the page can show each result as it arrives.
 */
class SlackNotificationTestController extends Controller
{
    use RespondsWithSlackErrors;

    public function __construct(private readonly SlackNotificationTestRunner $runner) {}

    /**
     * GET /api/integrations/slack/diagnostics/notification-tests
     *
     * Every test with what it needs (recipient, channel, scopes) and the scopes the active
     * workspace is missing for it.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->runner->catalog());
    }

    /**
     * GET /api/integrations/slack/diagnostics/slack-members
     *
     * Every person in the active Slack workspace, linked to the app or not, for the "message any
     * Slack member" picker. `?refresh=1` reads them from Slack again instead of the short cache.
     */
    public function slackMembers(SlackChannelIndexRequest $request, SlackService $slack_service): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $slack_service->listMembers($installation, $request->wantsFreshChannels())]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * POST /api/integrations/slack/diagnostics/notification-tests/{test}
     *
     * Runs one test against the real Slack workspace. A test that ran answers 200 even when it
     * failed, the failure is its result, with Slack's own error and every call it made.
     */
    public function run(SlackNotificationTestRequest $request, SlackNotificationTest $test): JsonResponse
    {
        $actor = $request->user();
        $recipient_id = $request->validated('user_id');
        $recipient = $recipient_id ? User::find($recipient_id) : null;
        $channel_id = $request->validated('channel_id');

        $slack_user_id = $request->validated('slack_user_id');

        $result = $this->runner->run($test, $actor, $recipient, $channel_id, $slack_user_id, $request->validated('message'));

        if ($test->sendsMessage()) {
            AuditLogger::log('slack.notification_test_run', "Ran the Slack test \"{$test->label()}\", it {$result['status']}.", $actor, array_filter([
                'test' => $test->value,
                'status' => $result['status'],
                'recipient_id' => $recipient?->id,
                'channel_id' => $channel_id,
                'slack_user_id' => $slack_user_id,
            ], fn ($value) => $value !== null));
        }

        return response()->json($result);
    }
}
