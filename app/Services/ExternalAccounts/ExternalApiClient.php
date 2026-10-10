<?php

namespace App\Services\ExternalAccounts;

use App\Enums\ExternalProvider;
use App\Models\ExternalAccount;
use App\Models\IntegrationAppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Google and Microsoft over plain HTTP, like `SlackClient`, no SDK involved: the OAuth
 * token endpoints, the signed in user's profile, and authorized calls on a connected account,
 * refreshing its access token first when it is about to expire.
 */
class ExternalApiClient
{
    private const TIMEOUT_SECONDS = 15;

    /** An access token this close to expiring is refreshed before it is used. */
    private const REFRESH_MARGIN_SECONDS = 120;

    public function __construct(private readonly ExternalAppCredentials $credentials) {}

    /**
     * Trades an authorization code for tokens.
     *
     * @return array<string, mixed>
     *
     * @throws ExternalAccountException
     */
    public function exchangeCode(ExternalProvider $provider, string $code): array
    {
        $setting = $this->credentials->require($provider);

        return $this->tokenRequest($provider, $setting, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->credentials->redirectUri($provider),
        ]);
    }

    /**
     * Who the tokens belong to: `id`, `email` and `name`.
     *
     * @return array{id: string, email: string|null, name: string|null}
     *
     * @throws ExternalAccountException
     */
    public function fetchProfile(ExternalProvider $provider, string $access_token): array
    {
        $response = $this->send(fn () => $this->http()->withToken($access_token)->get(match ($provider) {
            ExternalProvider::Google => 'https://openidconnect.googleapis.com/v1/userinfo',
            ExternalProvider::Microsoft => 'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',
        }));
        $this->throwIfFailed($response, "Your {$provider->label()} profile could not be read.");

        return match ($provider) {
            ExternalProvider::Google => [
                'id' => (string) $response->json('sub'),
                'email' => $response->json('email'),
                'name' => $response->json('name'),
            ],
            ExternalProvider::Microsoft => [
                'id' => (string) $response->json('id'),
                'email' => $response->json('mail') ?: $response->json('userPrincipalName'),
                'name' => $response->json('displayName'),
            ],
        };
    }

    /**
     * An authorized call on behalf of the account. A rejected token is refreshed once and the call
     * tried again, a second rejection means the member revoked access.
     *
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws ExternalAccountException
     */
    public function call(ExternalAccount $account, callable $call, string $failure_message): Response
    {
        $response = $this->send(fn () => $call($this->http()->withToken($this->accessToken($account))));

        if ($response->status() === 401) {
            $this->refresh($account);
            $response = $this->send(fn () => $call($this->http()->withToken($account->access_token)));
            if ($response->status() === 401) {
                throw $this->revoked($account);
            }
        }

        $this->throwIfFailed($response, $failure_message);

        return $response;
    }

    /**
     * Revokes the account's grant at Google. Microsoft has no revoke endpoint for one app, the
     * member removes it from their account page. Failures are ignored, the account is forgotten
     * either way.
     */
    public function revoke(ExternalAccount $account): void
    {
        if ($account->provider !== ExternalProvider::Google) {
            return;
        }

        try {
            $this->http()->asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $account->refresh_token ?: $account->access_token]);
        } catch (ConnectionException) {
            // Forgotten locally all the same.
        }
    }

    /**
     * A valid access token, refreshed when it expired or is about to.
     *
     * @throws ExternalAccountException
     */
    public function accessToken(ExternalAccount $account): string
    {
        $expires_at = $account->token_expires_at;
        if ($expires_at === null || $expires_at->isAfter(now()->addSeconds(self::REFRESH_MARGIN_SECONDS))) {
            return $account->access_token;
        }

        $this->refresh($account);

        return $account->access_token;
    }

    /**
     * @throws ExternalAccountException
     */
    private function refresh(ExternalAccount $account): void
    {
        if (! $account->refresh_token) {
            throw $this->revoked($account);
        }

        $setting = $this->credentials->require($account->provider);

        try {
            $payload = $this->tokenRequest($account->provider, $setting, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
        } catch (ExternalAccountException $exception) {
            throw $exception->error_code === 'invalid_grant' ? $this->revoked($account) : $exception;
        }

        $account->forceFill([
            'access_token' => (string) $payload['access_token'],
            // Microsoft rotates the refresh token, Google keeps the first one.
            'refresh_token' => $payload['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : null,
            'last_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     *
     * @throws ExternalAccountException
     */
    private function tokenRequest(ExternalProvider $provider, IntegrationAppSetting $setting, array $fields): array
    {
        $fields = [...$fields, 'client_id' => $setting->client_id, 'client_secret' => $setting->client_secret];
        if ($provider === ExternalProvider::Microsoft) {
            // Microsoft wants the scopes again on every token request.
            $scopes = $provider->signInScopes();
            foreach ($provider->services() as $service) {
                $scopes = [...$scopes, ...$service->scopes()];
            }
            $fields['scope'] = implode(' ', $scopes);
        }

        $response = $this->send(fn () => $this->http()->asForm()->post($provider->tokenUrl($setting->tenant_id), $fields));
        $payload = (array) $response->json();

        if ($response->failed() || empty($payload['access_token'])) {
            $error = is_string($payload['error'] ?? null) ? $payload['error'] : 'token_error';

            throw new ExternalAccountException($error, match ($error) {
                'invalid_client', 'unauthorized_client' => "The {$provider->label()} app rejected its client id or secret. Ask an administrator to check them in Administration > Integrations.",
                'invalid_grant' => "{$provider->label()} did not accept the authorization. Please connect again.",
                'redirect_uri_mismatch' => "The redirect URL is not registered in the {$provider->label()} app.",
                default => "{$provider->label()} could not complete the authorization. Please try again.",
            });
        }

        return $payload;
    }

    /**
     * @throws ExternalAccountException
     */
    private function throwIfFailed(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('error.message') ?? $response->json('error_description');
        $suffix = is_string($detail) && $detail !== '' ? " ({$detail})" : '';

        throw new ExternalAccountException(
            $response->status() === 403 ? ExternalAccountException::CODE_MISSING_SCOPES : ExternalAccountException::CODE_PROVIDER_ERROR,
            $response->status() === 403 ? "The account did not grant access to this. Connect it again and allow every permission.{$suffix}" : "{$message}{$suffix}",
            // Google answers 410 for an event that was deleted, both mean "not there any more".
            in_array($response->status(), [404, 410], true) ? 404 : 422,
        );
    }

    private function revoked(ExternalAccount $account): ExternalAccountException
    {
        $message = "{$account->provider->label()} no longer accepts the connection to {$account->email}. Connect the account again.";
        $account->forceFill(['last_error' => $message])->save();

        return new ExternalAccountException(ExternalAccountException::CODE_TOKEN_REVOKED, $message);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws ExternalAccountException
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new ExternalAccountException(ExternalAccountException::CODE_PROVIDER_ERROR, 'The provider could not be reached. Please try again in a moment.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();
    }
}
