<?php

namespace App\Services\Slack;

use App\Models\SlackAppSetting;
use App\Models\User;

/**
 * Where the Slack app credentials come from. An administrator can save them from
 * Administration > Integrations, those always win. While nothing was saved there the SLACK_*
 * values of the API environment are used, so an existing deployment keeps working untouched.
 *
 * The two sources are never mixed field by field: a saved client id with the environment's
 * secret would belong to two different Slack apps and fail in a confusing way.
 */
class SlackAppCredentials
{
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_ENVIRONMENT = 'environment';

    public const SOURCE_NONE = 'none';

    public function source(): string
    {
        if ($this->setting()) {
            return self::SOURCE_DATABASE;
        }

        return filled(config('services.slack.client_id')) && filled(config('services.slack.client_secret'))
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_NONE;
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function clientId(): ?string
    {
        $setting = $this->setting();

        return $setting ? $setting->client_id : $this->stringConfig('services.slack.client_id');
    }

    public function clientSecret(): ?string
    {
        $setting = $this->setting();

        return $setting ? $setting->client_secret : $this->stringConfig('services.slack.client_secret');
    }

    public function signingSecret(): ?string
    {
        $setting = $this->setting();

        return $setting ? $setting->signing_secret : $this->stringConfig('services.slack.signing_secret');
    }

    /**
     * Every signing secret a genuine Slack request may be signed with. The environment one is
     * kept next to the saved one, so a workspace installed with the previous app can still
     * report that it removed the app after an administrator switched to a different app.
     *
     * @return array<int, string>
     */
    public function acceptedSigningSecrets(): array
    {
        return array_values(array_unique(array_filter([
            $this->setting()?->signing_secret,
            $this->stringConfig('services.slack.signing_secret'),
        ], 'filled')));
    }

    /**
     * The OAuth redirect URL registered in the Slack app. A saved override is useful behind an
     * HTTPS tunnel, otherwise it follows SLACK_REDIRECT_URI or APP_URL.
     */
    public function redirectUri(): string
    {
        return $this->setting()?->redirect_uri ?: (string) config('services.slack.redirect');
    }

    /**
     * The Event Subscriptions request URL, which sits next to the OAuth callback.
     */
    public function eventsUrl(): string
    {
        $redirect_uri = $this->redirectUri();

        return str_ends_with($redirect_uri, '/callback')
            ? substr($redirect_uri, 0, -strlen('/callback')).'/events'
            : rtrim((string) config('app.url'), '/').'/api/integrations/slack/events';
    }

    /**
     * Saves the credentials. A blank secret keeps the saved one, so an administrator can change
     * the redirect URL without typing both secrets again, but only while the client id stays the
     * same, a different client id is a different Slack app with its own secrets.
     *
     * @param  array{client_id: string, client_secret?: string|null, signing_secret?: string|null, redirect_uri?: string|null}  $values
     *
     * @throws SlackException
     */
    public function save(array $values, User $actor): SlackAppSetting
    {
        $setting = $this->setting();
        $is_same_app = $setting && $setting->client_id === $values['client_id'];

        $client_secret = filled($values['client_secret'] ?? null) ? $values['client_secret'] : ($is_same_app ? $setting->client_secret : null);
        $signing_secret = filled($values['signing_secret'] ?? null) ? $values['signing_secret'] : ($is_same_app ? $setting->signing_secret : null);

        if (! $client_secret) {
            throw new SlackException('client_secret_required', 'Enter the client secret of this Slack app.');
        }

        $attributes = [
            'client_id' => $values['client_id'],
            'client_secret' => $client_secret,
            'signing_secret' => $signing_secret,
            'redirect_uri' => filled($values['redirect_uri'] ?? null) ? $values['redirect_uri'] : null,
            'updated_by_id' => $actor->id,
        ];

        if ($setting) {
            $setting->update($attributes);

            return $setting;
        }

        return SlackAppSetting::create($attributes);
    }

    /**
     * Forgets the saved credentials, the environment values apply again.
     */
    public function clear(): void
    {
        SlackAppSetting::query()->delete();
    }

    /**
     * What the administration page shows. Never a secret, only whether one is set and its
     * last four characters, so an administrator can tell which value is saved.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $setting = $this->setting();

        return [
            'source' => $this->source(),
            'is_configured' => $this->isConfigured(),
            'client_id' => $this->clientId(),
            'client_secret_hint' => $this->hint($this->clientSecret()),
            'signing_secret_hint' => $this->hint($this->signingSecret()),
            'has_environment_credentials' => filled(config('services.slack.client_id')) && filled(config('services.slack.client_secret')),
            'redirect_uri' => $this->redirectUri(),
            'redirect_uri_override' => $setting?->redirect_uri,
            'default_redirect_uri' => (string) config('services.slack.redirect'),
            'events_url' => $this->eventsUrl(),
            'bot_scopes' => SlackService::BOT_SCOPES,
            'user_scopes' => SlackService::USER_SCOPES,
            'updated_at' => $setting?->updated_at,
            'updated_by' => $setting?->updatedBy?->full_name,
            'manifest' => $this->manifest(),
        ];
    }

    /**
     * A Slack app manifest with every URL and scope this integration needs, so an administrator
     * can create the app at api.slack.com/apps with "From an app manifest" instead of filling
     * each setting by hand. Public distribution is what allows installing into many workspaces.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $app_name = (string) config('app.name', 'Workspace');

        return [
            'display_information' => [
                'name' => mb_substr($app_name, 0, 35),
                'description' => "Notifications and automation messages from {$app_name}.",
                'background_color' => '#1f1f2e',
            ],
            'features' => [
                'bot_user' => ['display_name' => mb_substr($app_name, 0, 80), 'always_online' => true],
                'app_home' => ['messages_tab_enabled' => true, 'messages_tab_read_only_enabled' => true],
            ],
            'oauth_config' => [
                'redirect_urls' => [$this->redirectUri()],
                'scopes' => [
                    'bot' => SlackService::BOT_SCOPES,
                    'user' => SlackService::USER_SCOPES,
                ],
            ],
            'settings' => [
                'event_subscriptions' => [
                    'request_url' => $this->eventsUrl(),
                    'bot_events' => ['app_uninstalled', 'tokens_revoked'],
                ],
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ],
        ];
    }

    private function setting(): ?SlackAppSetting
    {
        return SlackAppSetting::current();
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hint(?string $secret): ?string
    {
        return $secret ? '••••'.substr($secret, -4) : null;
    }
}
