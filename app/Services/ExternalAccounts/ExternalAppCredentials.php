<?php

namespace App\Services\ExternalAccounts;

use App\Enums\ExternalProvider;
use App\Models\IntegrationAppSetting;
use App\Models\User;

/**
 * The Google and Microsoft OAuth apps, saved by an administrator from Administration >
 * Integrations. Like the Slack app, nothing is read from the API environment.
 */
class ExternalAppCredentials
{
    /** Where the shared callback route lives, relative to APP_URL. */
    private const CALLBACK_PATH = '/api/integrations/accounts/callback';

    public function isConfigured(ExternalProvider $provider): bool
    {
        $setting = IntegrationAppSetting::forProvider($provider);

        return $setting !== null && filled($setting->client_id) && filled($setting->client_secret);
    }

    /**
     * @throws ExternalAccountException
     */
    public function require(ExternalProvider $provider): IntegrationAppSetting
    {
        $setting = IntegrationAppSetting::forProvider($provider);

        if (! $setting || ! filled($setting->client_id) || ! filled($setting->client_secret)) {
            throw new ExternalAccountException(ExternalAccountException::CODE_NOT_CONFIGURED, "The {$provider->label()} app is not set up yet. Ask an account administrator to set it up in Administration > Integrations.", 503);
        }

        return $setting;
    }

    /**
     * The OAuth redirect URL registered in the provider's app. A saved override is useful behind
     * an HTTPS tunnel, otherwise it is the API's own callback under APP_URL.
     */
    public function redirectUri(ExternalProvider $provider): string
    {
        return IntegrationAppSetting::forProvider($provider)?->redirect_uri ?: $this->defaultRedirectUri();
    }

    public function defaultRedirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').self::CALLBACK_PATH;
    }

    /**
     * Saves the app. A blank secret keeps the saved one, but only while the client id stays the
     * same, a different client id is a different app with its own secret.
     *
     * @param  array{client_id: string, client_secret?: string|null, tenant_id?: string|null, redirect_uri?: string|null}  $values
     *
     * @throws ExternalAccountException
     */
    public function save(ExternalProvider $provider, array $values, User $actor): IntegrationAppSetting
    {
        $setting = IntegrationAppSetting::forProvider($provider);
        $is_same_app = $setting && $setting->client_id === $values['client_id'];
        $client_secret = filled($values['client_secret'] ?? null) ? $values['client_secret'] : ($is_same_app ? $setting->client_secret : null);

        if (! $client_secret) {
            throw new ExternalAccountException('client_secret_required', "Enter the client secret of this {$provider->label()} app.");
        }

        return IntegrationAppSetting::updateOrCreate(['provider' => $provider->value], [
            'client_id' => $values['client_id'],
            'client_secret' => $client_secret,
            'tenant_id' => $provider === ExternalProvider::Microsoft && filled($values['tenant_id'] ?? null) ? $values['tenant_id'] : null,
            'redirect_uri' => filled($values['redirect_uri'] ?? null) ? $values['redirect_uri'] : null,
            'updated_by_id' => $actor->id,
        ]);
    }

    /**
     * Forgets the app. Connected accounts keep their tokens until they expire, but none can be
     * refreshed or connected until an app is saved again.
     */
    public function clear(ExternalProvider $provider): void
    {
        IntegrationAppSetting::query()->where('provider', $provider->value)->delete();
    }

    /**
     * What the administration page shows. Never the secret, only its last four characters.
     *
     * @return array<string, mixed>
     */
    public function describe(ExternalProvider $provider): array
    {
        $setting = IntegrationAppSetting::forProvider($provider);
        $scopes = $provider->signInScopes();
        foreach ($provider->services() as $service) {
            $scopes = [...$scopes, ...$service->scopes()];
        }

        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'services' => array_map(fn ($service) => ['id' => $service->value, 'label' => $service->label()], $provider->services()),
            'is_configured' => $this->isConfigured($provider),
            'client_id' => $setting?->client_id,
            'client_secret_hint' => $setting?->client_secret ? '••••'.substr($setting->client_secret, -4) : null,
            'tenant_id' => $setting?->tenant_id,
            'redirect_uri' => $this->redirectUri($provider),
            'redirect_uri_override' => $setting?->redirect_uri,
            'default_redirect_uri' => $this->defaultRedirectUri(),
            'scopes' => array_values(array_unique($scopes)),
            'console_url' => $provider->consoleUrl(),
            'updated_at' => $setting?->updated_at,
            'updated_by' => $setting?->updatedBy?->full_name,
        ];
    }
}
