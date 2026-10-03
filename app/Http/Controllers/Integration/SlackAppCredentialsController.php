<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integration\Concerns\RespondsWithSlackErrors;
use App\Http\Requests\Integration\SlackAppCredentialsRequest;
use App\Services\Slack\SlackAppCredentials;
use App\Services\Slack\SlackException;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations > Slack app (admin, super_admin). The Slack app credentials
 * are managed here instead of the API environment, so an administrator can point the
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
     * Forgets the saved credentials, the values of the API environment apply again.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->credentials->clear();

        AuditLogger::log('slack.app_credentials_cleared', 'Removed the saved Slack app credentials.', $request->user());

        return response()->json(['message' => 'Saved Slack app credentials removed.', ...$this->credentials->describe()]);
    }
}
