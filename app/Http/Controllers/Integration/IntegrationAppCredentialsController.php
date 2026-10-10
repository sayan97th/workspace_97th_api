<?php

namespace App\Http\Controllers\Integration;

use App\Enums\ExternalProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\IntegrationAppCredentialsRequest;
use App\Services\ExternalAccounts\ExternalAccountException;
use App\Services\ExternalAccounts\ExternalAppCredentials;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration > Integrations, Google and Microsoft (administrators and the account owner). Each
 * provider's OAuth app is saved here once, so members can connect Gmail, Google Calendar and
 * Outlook from the Automations center. Secrets are write only, only their last four characters
 * ever come back.
 */
class IntegrationAppCredentialsController extends Controller
{
    public function __construct(private readonly ExternalAppCredentials $credentials) {}

    /**
     * GET /api/integrations/apps
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (ExternalProvider $provider) => $this->credentials->describe($provider), ExternalProvider::cases())]);
    }

    /**
     * PUT /api/integrations/apps/{provider}
     */
    public function update(IntegrationAppCredentialsRequest $request, string $provider): JsonResponse
    {
        $provider = $this->resolveProvider($provider);

        try {
            $setting = $this->credentials->save($provider, $request->validated(), $request->user());
        } catch (ExternalAccountException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['client_secret' => [$exception->getMessage()]]], 422);
        }

        AuditLogger::log('integrations.app_saved', "Saved the {$provider->label()} app.", $request->user(), [
            'provider' => $provider->value,
            'client_id' => $setting->client_id,
        ]);

        return response()->json(['message' => "{$provider->label()} app saved.", ...$this->credentials->describe($provider)]);
    }

    /**
     * DELETE /api/integrations/apps/{provider}
     */
    public function destroy(Request $request, string $provider): JsonResponse
    {
        $provider = $this->resolveProvider($provider);
        $this->credentials->clear($provider);

        AuditLogger::log('integrations.app_removed', "Removed the {$provider->label()} app.", $request->user(), ['provider' => $provider->value]);

        return response()->json(['message' => "{$provider->label()} app removed.", ...$this->credentials->describe($provider)]);
    }

    private function resolveProvider(string $provider): ExternalProvider
    {
        return ExternalProvider::tryFrom($provider) ?? abort(404);
    }
}
