<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Models\BoardAutomation;
use App\Models\SlackInstallation;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use App\Services\Slack\SlackStatus;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations > Slack workspaces (admin, super_admin), the counterpart of
 * monday.com's Connections page. Lists every connected Slack workspace, switches the active
 * one, disconnects one, and matches members to their Slack account by email.
 *
 * Adding or reconnecting a workspace goes through "Add to Slack", see
 * {@see SlackIntegrationController::installUrl()}.
 */
class SlackWorkspaceController extends Controller
{
    use RespondsWithSlackErrors;

    public function __construct(
        private readonly SlackService $slack_service,
        private readonly SlackStatus $slack_status,
    ) {}

    /**
     * GET /api/integrations/slack/workspaces
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->presentWorkspaces()]);
    }

    /**
     * POST /api/integrations/slack/workspaces/{installation}/activate
     *
     * Notifications, automations and "Connect my Slack" use the active workspace from now on.
     * Member links to every workspace are kept, so switching back needs no new sign in.
     */
    public function activate(Request $request, SlackInstallation $installation): JsonResponse
    {
        if (! $installation->is_active) {
            $this->slack_service->activate($installation);
            AuditLogger::log('slack.workspace_activated', "Switched Slack to the \"{$installation->team_name}\" workspace.", $request->user(), [
                'team_id' => $installation->team_id,
            ]);
        }

        return response()->json([
            'message' => "{$installation->team_name} is now the active Slack workspace.",
            ...$this->payload($request),
        ]);
    }

    /**
     * DELETE /api/integrations/slack/workspaces/{installation}
     *
     * Revokes the workspace's bot token and removes every member link made against it. When it
     * was the active one the most recently added remaining workspace takes over. Once no
     * workspace is left every automation that posts to Slack is switched off, so none of them
     * keeps failing silently.
     */
    public function destroy(Request $request, SlackInstallation $installation): JsonResponse
    {
        $team_name = $installation->team_name;
        $was_active = $installation->is_active;
        $next_active = $this->slack_service->disconnect($installation);

        if (! $next_active) {
            BoardAutomation::whereIn('action_type', BoardAutomation::slackActions())->update(['is_enabled' => false]);
        }

        AuditLogger::log('slack.disconnected', "Disconnected the Slack workspace \"{$team_name}\".", $request->user(), [
            'team_id' => $installation->team_id,
        ]);

        $message = match (true) {
            $next_active === null => 'Slack disconnected. Automations that post to Slack were switched off.',
            $was_active => "{$team_name} was disconnected. {$next_active->team_name} is now the active Slack workspace.",
            default => "{$team_name} was disconnected.",
        };

        return response()->json(['message' => $message, ...$this->payload($request)]);
    }

    /**
     * DELETE /api/integrations/slack
     *
     * Disconnects the active workspace, kept for the screens that only know about one.
     */
    public function destroyActive(Request $request): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['message' => 'Slack is not connected.'], 404);
        }

        return $this->destroy($request, $installation);
    }

    /**
     * POST /api/integrations/slack/match-members
     *
     * Links every member whose email matches a member of the active Slack workspace.
     */
    public function matchMembers(Request $request): JsonResponse
    {
        $installation = SlackInstallation::current();

        if (! $installation) {
            return response()->json(['message' => 'Slack is not connected.'], 404);
        }

        try {
            $result = $this->slack_service->matchMembersByEmail($installation);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        if ($result['matched'] > 0) {
            AuditLogger::log('slack.members_matched', "Matched {$result['matched']} members to their Slack account in \"{$installation->team_name}\".", $request->user());
        }

        $message = $result['matched'] === 1
            ? '1 member was matched to their Slack account.'
            : "{$result['matched']} members were matched to their Slack account.";

        if ($result['unmatched'] > 0) {
            $message .= " {$result['unmatched']} could not be found in {$installation->team_name} by email, they can use \"Connect my Slack\" instead.";
        }

        return response()->json(['message' => $message, 'result' => $result, ...$this->payload($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        return [...$this->slack_status->forUser($request->user()), 'workspaces' => $this->presentWorkspaces()];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentWorkspaces(): array
    {
        return $this->slack_service->installations()
            ->map(fn (SlackInstallation $installation) => [
                'id' => $installation->id,
                'team_id' => $installation->team_id,
                'team_name' => $installation->team_name,
                'team_url' => $installation->team_url,
                'is_active' => $installation->is_active,
                'connected_at' => $installation->created_at,
                'updated_at' => $installation->updated_at,
                'connected_by' => $installation->installedBy?->full_name,
                'linked_members_count' => $installation->user_links_count,
                'missing_scopes' => array_values(array_filter(SlackService::BOT_SCOPES, fn (string $scope) => ! $installation->hasScope($scope))),
            ])
            ->all();
    }
}
