<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackAppCreateRequest;
use App\Http\Requests\Integration\SlackAppCredentialsRequest;
use App\Services\Slack\SlackAppCredentials;
use App\Services\Slack\SlackAppProvisioner;
use App\Services\Slack\SlackException;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations > Developer settings (account owner only, `super_admin`). The Slack app credentials
 * are managed only here, never in the API environment, so an administrator can point the
 * integration at a different Slack app at any time. Secrets are write only, the API only
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
     */
    public function update(SlackAppCredentialsRequest $request): JsonResponse
    {
        try {
            $this->credentials->save($request->validated(), $request->user());
        } catch (SlackException $exception) {
            return $this->errorResponse($exception);
        }

        AuditLogger::log('slack.app_credentials_updated', 'Updated the Slack app credentials.', $request->user(), [
            'client_id' => $request->validated('client_id'),
        ]);

        return response()->json(['message' => 'Slack app credentials saved.', ...$this->credentials->describe()]);
    }

    /**
     * DELETE /api/integrations/slack/app
     *
     * Forgets the saved credentials. Connected workspaces keep working, new ones cannot be added until an app is saved again.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->credentials->clear();

        AuditLogger::log('slack.app_credentials_cleared', 'Removed the saved Slack app credentials.', $request->user());

        return response()->json(['message' => 'Saved Slack app credentials removed.', ...$this->credentials->describe()]);
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
