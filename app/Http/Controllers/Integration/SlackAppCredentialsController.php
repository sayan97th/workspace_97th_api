<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackAppCreateRequest;
use App\Http\Requests\Integration\SlackAppCredentialsRequest;
use App\Http\Requests\Integration\SlackAppSelectionRequest;
use App\Services\Slack\SlackAppCredentials;
use App\Services\Slack\SlackAppProvisioner;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations > Slack (administrators and the account owner, `admin`, `super_admin`).
 * The production 97th Floor Slack app comes from the API environment and is read only here. The
 * testing app (for example Make It Simple) is saved, replaced and removed here, and an
 * administrator switches between the two, see {@see SlackAppCredentials}. Secrets are write
 * only, the API only ever answers with their last four characters.
 */
class SlackAppCredentialsController extends Controller
{
    use RespondsWithSlackErrors;

    public function __construct(private readonly SlackAppCredentials $credentials) {}

    /**
     * GET /api/integrations/slack/app
     */
    public function show(): JsonResponse
    {
        return response()->json($this->credentials->describe());
    }

    /**
     * POST /api/integrations/slack/app/create
     *
     * Creates the Slack app from the site's own manifest with an app configuration token and
     * saves its credentials, the one step setup. The token is never stored.
     */
    public function create(SlackAppCreateRequest $request, SlackAppProvisioner $provisioner): JsonResponse
    {
        try {
            $setting = $provisioner->createApp($request->validated('configuration_token'), $request->user());
        } catch (SlackException $exception) {
            return $this->createErrorResponse($exception);
        }

        AuditLogger::log('slack.app_created', 'Created the Slack app from the site.', $request->user(), [
            'app_id' => $setting->app_id,
            'client_id' => $setting->client_id,
        ]);

        return response()->json([
            'message' => 'Slack app created. Turn on public distribution in Slack to add it to more than one workspace.',
            ...$this->credentials->describe(),
        ], 201);
    }

    /**
     * PUT /api/integrations/slack/app
     *
     * Saves the testing app, it is not used until an administrator switches to it.
     */
    public function update(SlackAppCredentialsRequest $request): JsonResponse
    {
        try {
            $this->credentials->save($request->validated(), $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        AuditLogger::log('slack.app_credentials_updated', 'Updated the testing Slack app credentials.', $request->user(), [
            'client_id' => $request->validated('client_id'),
        ]);

        return response()->json(['message' => 'Testing Slack app saved.', ...$this->credentials->describe()]);
    }

    /**
     * PUT /api/integrations/slack/app/active
     *
     * Switches between the production app (`environment`) and the testing app (`database`). The
     * active workspace follows the app when one was installed with it.
     */
    public function select(SlackAppSelectionRequest $request, SlackService $slack_service): JsonResponse
    {
        $source = $request->validated('source');

        try {
            $installation = $slack_service->useApp($source, $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        $app_name = $this->credentials->appName();

        AuditLogger::log('slack.app_selected', "Switched Slack to the {$app_name} app.", $request->user(), [
            'source' => $source,
            'client_id' => $this->credentials->clientId(),
        ]);

        $message = $installation && $this->credentials->sourceOfAppId($installation->app_id) === $source
            ? "Slack now uses the {$app_name} app and the {$installation->team_name} workspace."
            : "Slack now uses the {$app_name} app. Add a workspace with it to start sending messages.";

        return response()->json(['message' => $message, ...$this->credentials->describe()]);
    }

    /**
     * DELETE /api/integrations/slack/app
     *
     * Forgets the testing app, the production app from the environment takes over.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->credentials->clear();

        AuditLogger::log('slack.app_credentials_cleared', 'Removed the testing Slack app.', $request->user());

        return response()->json(['message' => 'Testing Slack app removed.', ...$this->credentials->describe()]);
    }

    /**
     * Errors of `apps.manifest.create` mean something else than the same codes elsewhere: an
     * expired token here is the configuration token, not a workspace's bot token.
     */
    private function createErrorResponse(SlackException $exception): JsonResponse
    {
        $message = match ($exception->error_code) {
            'invalid_auth', 'not_authed', 'token_expired', 'token_revoked', 'not_allowed_token_type' => 'Slack did not accept that configuration token. Tokens expire after 12 hours, generate a new one at api.slack.com/apps and paste the access token.',
            'invalid_manifest' => 'Slack rejected the app settings: '.(implode('; ', $exception->details) ?: 'no details were given').'.',
            'no_permission', 'team_access_not_granted', 'access_denied', 'enterprise_is_restricted' => 'Your Slack account is not allowed to create apps in that workspace. Ask a Slack admin of the workspace, or generate the token in a workspace where you can create apps.',
            'two_factor_setup_required' => 'Slack requires two factor authentication on your account before you can create apps.',
            default => null,
        };

        if ($message === null) {
            return $this->errorResponse($exception);
        }

        return response()->json(['message' => $message, 'errors' => ['configuration_token' => [$message]]], 422);
    }
}
