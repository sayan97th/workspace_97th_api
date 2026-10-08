<?php

namespace App\Http\Controllers\Integration;

use App\Enums\SlackNotificationTest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\SlackNotificationTestRequest;
use App\Models\User;
use App\Services\Slack\SlackNotificationTestRunner;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * The Slack notification test suite of /admin/test/slack/notifications (admin, super_admin).
 * Lists every test and runs them one at a time, so the page can show each result as it arrives.
 */
class SlackNotificationTestController extends Controller
{
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

        $result = $this->runner->run($test, $actor, $recipient, $channel_id);

        if ($test->sendsMessage()) {
            AuditLogger::log('slack.notification_test_run', "Ran the Slack test \"{$test->label()}\", it {$result['status']}.", $actor, array_filter([
                'test' => $test->value,
                'status' => $result['status'],
                'recipient_id' => $recipient?->id,
                'channel_id' => $channel_id,
            ], fn ($value) => $value !== null));
        }

        return response()->json($result);
    }
}
