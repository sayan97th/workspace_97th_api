<?php

namespace App\Services\Slack;

use App\Models\SlackAppSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The Slack app credentials, saved by an administrator from Administration > Integrations > Slack.
 * Like monday.com, nothing about Slack is read from the API environment: the app, the
 * workspaces connected to it and the active workspace are all managed from the site.
 */
class SlackAppCredentials
{
    /** Where the callback route lives, relative to APP_URL. */
    private const CALLBACK_PATH = '/api/integrations/slack/callback';

    public const SOURCE_DATABASE = 'database';

    public const SOURCE_NONE = 'none';

    public function source(): string
    {
        return $this->setting() ? self::SOURCE_DATABASE : self::SOURCE_NONE;
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function clientId(): ?string
    {
        return $this->setting()?->client_id;
    }

    public function clientSecret(): ?string
    {
        return $this->setting()?->client_secret;
    }

    public function signingSecret(): ?string
    {
        $signing_secret = $this->setting()?->signing_secret;

        return filled($signing_secret) ? $signing_secret : null;
    }

    /**
     * The OAuth redirect URL registered in the Slack app. A saved override is useful behind an
     * HTTPS tunnel, otherwise it is the API's own callback under APP_URL.
     */
    public function redirectUri(): string
    {
        return $this->setting()?->redirect_uri ?: $this->defaultRedirectUri();
    }

    public function defaultRedirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').self::CALLBACK_PATH;
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
            // The app id is only known for an app the site created, a different client id is a different app.
            'app_id' => $is_same_app ? $setting->app_id : null,
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
     * Saves the credentials of an app the site just created in Slack, replacing any previous
     * app. A redirect URL override is kept, it describes how Slack reaches this API, not the app.
     */
    public function storeCreatedApp(string $app_id, string $client_id, string $client_secret, ?string $signing_secret, User $actor): SlackAppSetting
    {
        $redirect_uri = $this->setting()?->redirect_uri;

        SlackAppSetting::query()->delete();

        return SlackAppSetting::create([
            'app_id' => $app_id,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'signing_secret' => $signing_secret,
            'redirect_uri' => $redirect_uri,
            'updated_by_id' => $actor->id,
        ]);
    }

    /**
     * Forgets the saved credentials. Connected workspaces keep their bot tokens, but no new
     * workspace can be connected until an app is saved again.
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
            'app_id' => $setting?->app_id,
            // Where to finish the setup in Slack, only known for an app the site created.
            'app_settings_url' => $setting?->app_id ? "https://api.slack.com/apps/{$setting->app_id}" : null,
            'distribution_url' => $setting?->app_id ? "https://api.slack.com/apps/{$setting->app_id}/distribute" : null,
            'can_receive_events' => $this->canReceiveEvents(),
            'client_id' => $this->clientId(),
            'client_secret_hint' => $this->hint($this->clientSecret()),
            'signing_secret_hint' => $this->hint($this->signingSecret()),
            'redirect_uri' => $this->redirectUri(),
            'redirect_uri_override' => $setting?->redirect_uri,
            'default_redirect_uri' => $this->defaultRedirectUri(),
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
                // Slack shows the bot as @display_name, so it is kept to a mention friendly slug.
                'bot_user' => ['display_name' => mb_substr(Str::slug($app_name, '_') ?: 'workspace_bot', 0, 80), 'always_online' => true],
                'app_home' => ['messages_tab_enabled' => true, 'messages_tab_read_only_enabled' => true],
            ],
            'oauth_config' => [
                'redirect_urls' => [$this->redirectUri()],
                'scopes' => [
                    'bot' => SlackService::BOT_SCOPES,
                    'user' => SlackService::USER_SCOPES,
                ],
            ],
            'settings' => array_filter([
                // Slack checks the request URL when the app is created, so it is only included
                // when Slack can reach it, a local API would make the whole manifest fail.
                'event_subscriptions' => $this->canReceiveEvents() ? [
                    'request_url' => $this->eventsUrl(),
                    'bot_events' => ['app_uninstalled', 'tokens_revoked', 'app_mention'],
                ] : null,
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Whether Slack can call the events URL: HTTPS on a public host, not a local API.
     */
    public function canReceiveEvents(): bool
    {
        $url = $this->eventsUrl();
        $host = (string) parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local');
    }

    private function setting(): ?SlackAppSetting
    {
        return SlackAppSetting::current();
    }

    private function hint(?string $secret): ?string
    {
        return $secret ? '••••'.substr($secret, -4) : null;
    }
}
