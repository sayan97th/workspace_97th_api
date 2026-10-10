<?php

namespace App\Enums;

/**
 * Who a member signs in with to connect an outside app: Google for Gmail and Google Calendar,
 * Microsoft for Outlook. Each provider has one OAuth app, saved by an administrator from
 * Administration > Integrations, see `ExternalAppCredentials`.
 */
enum ExternalProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    /** The Microsoft tenant used when an administrator leaves it empty: work, school and personal accounts. */
    public const DEFAULT_MICROSOFT_TENANT = 'common';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }

    public function authorizeUrl(?string $tenant = null): string
    {
        return match ($this) {
            self::Google => 'https://accounts.google.com/o/oauth2/v2/auth',
            self::Microsoft => 'https://login.microsoftonline.com/'.($tenant ?: self::DEFAULT_MICROSOFT_TENANT).'/oauth2/v2.0/authorize',
        };
    }

    public function tokenUrl(?string $tenant = null): string
    {
        return match ($this) {
            self::Google => 'https://oauth2.googleapis.com/token',
            self::Microsoft => 'https://login.microsoftonline.com/'.($tenant ?: self::DEFAULT_MICROSOFT_TENANT).'/oauth2/v2.0/token',
        };
    }

    /**
     * The scopes every connection asks for, so the account can be named and refreshed.
     *
     * @return array<int, string>
     */
    public function signInScopes(): array
    {
        return match ($this) {
            self::Google => ['openid', 'email', 'profile'],
            self::Microsoft => ['openid', 'email', 'profile', 'offline_access', 'User.Read'],
        };
    }

    /**
     * Where an administrator creates the OAuth app.
     */
    public function consoleUrl(): string
    {
        return match ($this) {
            self::Google => 'https://console.cloud.google.com/apis/credentials',
            self::Microsoft => 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        };
    }

    /**
     * @return array<int, ExternalService>
     */
    public function services(): array
    {
        return array_values(array_filter(ExternalService::cases(), fn (ExternalService $service) => $service->provider() === $this));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider) => $provider->value, self::cases());
    }
}
