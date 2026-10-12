<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackAppCreateRequest;
use App\Http\Requests\Integration\SlackAppCredentialsRequest;
use App\Http\Requests\Integration\SlackAppSelectionRequest;
use App\Models\SlackAppSetting;
use App\Services\Slack\SlackAppCredentials;
use App\Services\Slack\SlackAppProvisioner;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations > Slack (administrators and the account owner, `admin`, `super_admin`).
 * The 97th Floor Slack app comes from the API environment and is read only here. Other Slack
 * apps (for example Make It Simple) are saved, edited and removed here, and an administrator
 * selects the one in use, see {@see SlackAppCredentials}. Secrets are write only, the API only
 * ever answers with their last four characters.
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
            $setting = $provisioner->createApp($request->validated('configuration_token'), $request->validated('label'), $request->user());
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
     * POST /api/integrations/slack/app
     *
     * Saves another Slack app. It is not used until an administrator selects it.
     */
    public function store(SlackAppCredentialsRequest $request): JsonResponse
    {
        try {
            $setting = $this->credentials->save($request->validated(), $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        AuditLogger::log('slack.app_credentials_created', "Saved the {$setting->label} Slack app.", $request->user(), [
            'client_id' => $setting->client_id,
        ]);

        return response()->json(['message' => "{$setting->label} saved. Select it to start using it.", ...$this->credentials->describe()], 201);
    }

    /**
     * PUT /api/integrations/slack/app/{slack_app}
     */
    public function update(SlackAppCredentialsRequest $request, SlackAppSetting $slack_app): JsonResponse
    {
        try {
            $this->credentials->save($request->validated(), $request->user(), $slack_app);
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        AuditLogger::log('slack.app_credentials_updated', 'Updated the Slack app credentials.', $request->user(), [
            'client_id' => $slack_app->client_id,
        ]);

        return response()->json(['message' => 'Slack app credentials saved.', ...$this->credentials->describe()]);
    }

    /**
     * PUT /api/integrations/slack/app/active
     *
     * Selects the app in use, `environment` or a saved app's id. The active workspace follows the
     * app when one was installed with it.
     */
    public function select(SlackAppSelectionRequest $request, SlackService $slack_service): JsonResponse
    {
        $app_key = $request->validated('app');

        try {
            $installation = $slack_service->useApp($app_key, $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        $app_name = $this->credentials->appName();

        AuditLogger::log('slack.app_selected', "Switched Slack to the {$app_name} app.", $request->user(), [
            'app' => $app_key,
            'client_id' => $this->credentials->clientId(),
        ]);

        $is_workspace_of_app = $installation && ($this->credentials->appForAppId($installation->app_id)['key'] ?? null) === $app_key;

        $message = $is_workspace_of_app
            ? "Slack now uses the {$app_name} app and the {$installation->team_name} workspace."
            : "Slack now uses the {$app_name} app. Add a workspace with it to start sending messages.";

        return response()->json(['message' => $message, ...$this->credentials->describe()]);
    }

    /**
     * DELETE /api/integrations/slack/app/{slack_app}
     *
     * Forgets a saved app. When it was in use, the app from the environment takes over.
     */
    public function destroy(Request $request, SlackAppSetting $slack_app): JsonResponse
    {
        $name = $slack_app->label ?: 'The Slack app';
        $this->credentials->remove($slack_app);

        AuditLogger::log('slack.app_credentials_cleared', "Removed the {$name} Slack app.", $request->user(), [
            'client_id' => $slack_app->client_id,
        ]);

        return response()->json(['message' => "{$name} removed.", ...$this->credentials->describe()]);
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
