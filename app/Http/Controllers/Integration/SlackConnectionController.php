<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackChannelIndexRequest;
use App\Http\Requests\Integration\SlackConnectRequest;
use App\Models\BoardAutomation;
use App\Models\SlackConnection;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use App\Support\AccountPermissions;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A member's own Slack accounts for automations, the "Connect your Slack account" step of the
 * Automations center, modeled on monday.com: pick a Slack recipe, connect an account (or pick one
 * connected before), then fill the recipe. Every endpoint only ever sees the caller's connections.
 */
class SlackConnectionController extends Controller
{
    use RespondsWithSlackErrors;

    public function __construct(private readonly SlackService $slack_service) {}

    /**
     * GET /api/integrations/slack/connections
     *
     * The caller's connected Slack accounts, the newest first, with how many automations use each,
     * plus whether they may connect another one.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $connections = $this->slack_service->connectionsFor($user);
        $usage = $this->automationCounts($connections->modelKeys());

        return response()->json([
            'data' => $connections->map(fn (SlackConnection $connection) => $this->present($connection, $usage[$connection->id] ?? 0))->values(),
            'is_configured' => $this->slack_service->isConfigured(),
            'can_connect' => $this->slack_service->isConfigured() && AccountPermissions::allows($user, AccountPermissions::USE_INTEGRATIONS),
        ]);
    }

    /**
     * POST /api/integrations/slack/connections/url
     *
     * The Slack authorization URL for "Connect", fetched by the frontend and opened in a new tab
     * since a top level navigation cannot carry the JWT.
     */
    public function url(SlackConnectRequest $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->slack_service->buildConnectUrl($request->user(), $request->validated('return_path'), $request->display())]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * GET /api/integrations/slack/connections/{connection}/channels
     *
     * Channels of the connection's workspace, for the recipe's channel picker. `?refresh=1` reads
     * them from Slack again instead of the short lived cache.
     */
    public function channels(SlackChannelIndexRequest $request, SlackConnection $connection): JsonResponse
    {
        $this->ensureOwnedBy($request, $connection);

        try {
            return response()->json(['data' => $this->slack_service->listChannels($connection->installation, $request->wantsFreshChannels())]);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * DELETE /api/integrations/slack/connections/{connection}
     *
     * Removes one of the caller's accounts. The workspace stays connected for everyone else.
     */
    public function destroy(Request $request, SlackConnection $connection): JsonResponse
    {
        $this->ensureOwnedBy($request, $connection);

        $team_name = $connection->installation->team_name;
        $usage = $this->automationCounts([$connection->id])[$connection->id] ?? 0;

        $this->slack_service->disconnectConnection($connection);
        AuditLogger::log('slack.account_disconnected', "Disconnected a Slack account in \"{$team_name}\" from automations.", $request->user(), [
            'connection_id' => $connection->id,
        ]);

        $message = $usage > 0
            ? "Your {$team_name} Slack account was disconnected. {$usage} ".($usage === 1 ? 'automation stops' : 'automations stop').' posting to Slack until you pick another account.'
            : "Your {$team_name} Slack account was disconnected.";

        return response()->json(['message' => $message]);
    }

    /**
     * A connection that belongs to someone else answers 404, so ids cannot be probed.
     */
    private function ensureOwnedBy(Request $request, SlackConnection $connection): void
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SlackConnection $connection, int $automations_count): array
    {
        $installation = $connection->installation;

        return [
            'id' => $connection->id,
            'team_id' => $installation->team_id,
            'team_name' => $installation->team_name,
            'team_url' => $installation->team_url,
            'slack_user_id' => $connection->slack_user_id,
            'slack_user_name' => $connection->slack_user_name,
            'is_active_workspace' => $installation->is_active,
            'connected_at' => $connection->connected_at,
            'automations_count' => $automations_count,
        ];
    }

    /**
     * How many automations post through each connection. The id lives inside the `actions` and
     * `else_actions` JSON, so candidates are narrowed with a text match and counted here.
     *
     * @param  array<int, int>  $connection_ids
     * @return array<int, int>
     */
    private function automationCounts(array $connection_ids): array
    {
        if ($connection_ids === []) {
            return [];
        }

        $counts = [];
        BoardAutomation::query()
            ->where(fn ($query) => $query->where('actions', 'like', '%slack_connection_id%')->orWhere('else_actions', 'like', '%slack_connection_id%'))
            ->select(['id', 'action_params', 'actions', 'else_actions'])
            ->chunkById(500, function ($automations) use ($connection_ids, &$counts) {
                foreach ($automations as $automation) {
                    foreach ($automation->slackConnectionIds() as $connection_id) {
                        if (in_array($connection_id, $connection_ids, true)) {
                            $counts[$connection_id] = ($counts[$connection_id] ?? 0) + 1;
                        }
                    }
                }
            });

        return $counts;
    }
}
