<?php

namespace App\Services\Slack;

use App\Models\SlackAppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which Slack app the integration uses, and its credentials. An administrator picks one app from
 * Administration > Integrations > Slack:
 *
 * - The app set in the API environment (the SLACK_* variables in `config/services.php`), the
 *   97th Floor app. It is used whenever no saved app was selected.
 * - Any number of apps saved from the site (`slack_app_settings`), for example the Make It
 *   Simple app. One of them is used while it is selected (`is_active`), or when the environment
 *   has no app at all.
 *
 * Values of two apps are never mixed, every credential comes from the app in use.
 */
class SlackAppCredentials
{
    /** Where the callback route lives, relative to APP_URL. */
    private const CALLBACK_PATH = '/api/integrations/slack/callback';

    /** The app in use comes from the API environment. */
    public const SOURCE_ENVIRONMENT = 'environment';

    /** The app in use was saved from Administration. */
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_NONE = 'none';

    /** Key of the app read from the API environment, saved apps are keyed by their id. */
    public const ENVIRONMENT_APP_KEY = 'environment';

    public function source(): string
    {
        if ($this->activeSetting()) {
            return self::SOURCE_DATABASE;
        }

        return $this->isEnvironmentConfigured() ? self::SOURCE_ENVIRONMENT : self::SOURCE_NONE;
    }

    /**
     * The key of the app in use, `environment` or a saved app's id, null when none is set up.
     */
    public function activeKey(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => self::ENVIRONMENT_APP_KEY,
            self::SOURCE_DATABASE => (string) $this->activeSetting()?->id,
            default => null,
        };
    }

    /**
     * The saved app in use: the selected one, or the first one saved when the environment has no
     * app, so saving another app never changes the app in use on its own.
     */
    public function activeSetting(): ?SlackAppSetting
    {
        $selected = SlackAppSetting::query()->where('is_active', true)->latest('id')->first();

        if ($selected || $this->isEnvironmentConfigured()) {
            return $selected;
        }

        return SlackAppSetting::query()->oldest('id')->first();
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    /**
     * Whether the client id and secret of the environment app are set.
     */
    public function isEnvironmentConfigured(): bool
    {
        return filled($this->environmentValue('client_id')) && filled($this->environmentValue('client_secret'));
    }

    public function clientId(): ?string
    {
        return $this->activeValue('client_id');
    }

    public function clientSecret(): ?string
    {
        return $this->activeValue('client_secret');
    }

    public function signingSecret(): ?string
    {
        return $this->activeValue('signing_secret');
    }

    /**
     * Every signing secret Slack may sign a request with, the app in use first. Every app keeps
     * sending events to its own workspaces (an uninstall, a revoked token) while another one is
     * in use, so a request signed by any of them is genuine.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $signing_secrets = [
            $this->signingSecret(),
            $this->environmentValue('signing_secret'),
            ...SlackAppSetting::query()->get()->map(fn (SlackAppSetting $setting) => $setting->signing_secret)->all(),
        ];

        return array_values(array_unique(array_filter($signing_secrets, fn (?string $secret) => filled($secret))));
    }

    /**
     * The Slack app id of the app in use, known for the environment app (SLACK_APP_ID), for an
     * app the site created, and for one that was already installed into a workspace.
     */
    public function appId(): ?string
    {
        return $this->activeValue('app_id');
    }

    /**
     * The name shown for the app in use.
     */
    public function appName(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentAppName(),
            self::SOURCE_DATABASE => $this->settingName($this->activeSetting()),
            default => null,
        };
    }

    /**
     * The app a workspace was installed with, from the app id Slack returned at install time.
     * Null when it matches no known app, for example an app that was removed.
     *
     * @return array{key: string, name: string}|null
     */
    public function appForAppId(?string $app_id): ?array
    {
        if (! filled($app_id)) {
            return null;
        }

        if ($app_id === $this->environmentValue('app_id')) {
            return ['key' => self::ENVIRONMENT_APP_KEY, 'name' => $this->environmentAppName()];
        }

        $setting = SlackAppSetting::query()->where('app_id', $app_id)->latest('id')->first();

        return $setting ? ['key' => (string) $setting->id, 'name' => $this->settingName($setting)] : null;
    }

    /**
     * The OAuth redirect URL registered in the Slack app in use. An override (SLACK_REDIRECT_URI,
     * or a saved app's own) is useful behind an HTTPS tunnel, otherwise it is the API's own
     * callback under APP_URL.
     */
    public function redirectUri(): string
    {
        $override = $this->activeValue('redirect_uri');

        return filled($override) ? $override : $this->defaultRedirectUri();
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
     * Saves a new app, or updates `$setting`. A blank secret keeps the saved one, so an
     * administrator can change the redirect URL without typing both secrets again, but only while
     * the client id stays the same, a different client id is a different Slack app.
     *
     * Saving never selects the app, that is {@see useApp()}, so the app in use only changes when
     * an administrator switches on purpose.
     *
     * @param  array{label?: string|null, client_id: string, client_secret?: string|null, signing_secret?: string|null, redirect_uri?: string|null}  $values
     *
     * @throws SlackException
     */
    public function save(array $values, User $actor, ?SlackAppSetting $setting = null): SlackAppSetting
    {
        $is_same_app = $setting && $setting->client_id === $values['client_id'];

        $client_secret = filled($values['client_secret'] ?? null) ? $values['client_secret'] : ($is_same_app ? $setting->client_secret : null);
        $signing_secret = filled($values['signing_secret'] ?? null) ? $values['signing_secret'] : ($is_same_app ? $setting->signing_secret : null);

        if (! $client_secret) {
            throw new SlackException('client_secret_required', 'Enter the client secret of this Slack app.');
        }

        $attributes = [
            // The app id is only known for an app the site created, a different client id is a different app.
            'app_id' => $is_same_app ? $setting->app_id : null,
            'label' => array_key_exists('label', $values) ? (filled($values['label']) ? trim($values['label']) : null) : $setting?->label,
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
     * Saves an app the site just created in Slack, next to the other apps. It keeps the redirect
     * URL override of the app in use, which describes how Slack reaches this API, not the app.
     */
    public function storeCreatedApp(string $app_id, string $client_id, string $client_secret, ?string $signing_secret, ?string $label, User $actor): SlackAppSetting
    {
        return SlackAppSetting::create([
            'app_id' => $app_id,
            'label' => filled($label) ? trim($label) : null,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'signing_secret' => $signing_secret,
            'redirect_uri' => $this->activeSetting()?->redirect_uri,
            'updated_by_id' => $actor->id,
        ]);
    }

    /**
     * Selects the app the integration uses, `environment` or a saved app's id. Connected
     * workspaces stay connected, see {@see SlackService::useApp()} for how the active workspace
     * follows the app.
     *
     * @throws SlackException
     */
    public function useApp(string $app_key, User $actor): void
    {
        if ($app_key === self::ENVIRONMENT_APP_KEY) {
            if (! $this->isEnvironmentConfigured()) {
                throw new SlackException('environment_app_missing', "The {$this->environmentAppName()} Slack app is not set on the server. Add SLACK_CLIENT_ID and SLACK_CLIENT_SECRET to the API .env file.");
            }

            SlackAppSetting::query()->where('is_active', true)->update(['is_active' => false]);

            return;
        }

        $setting = ctype_digit($app_key) ? SlackAppSetting::query()->find((int) $app_key) : null;

        if (! $setting) {
            throw new SlackException('app_not_found', 'That Slack app no longer exists. Reload the page and try again.');
        }

        DB::transaction(function () use ($setting, $actor) {
            SlackAppSetting::query()->whereKeyNot($setting->id)->where('is_active', true)->update(['is_active' => false]);
            $setting->forceFill(['is_active' => true, 'updated_by_id' => $actor->id])->save();
        });
    }

    /**
     * Remembers a saved app's id the first time Slack returns it, during an install, so its
     * workspaces can be told apart from the other apps' ones.
     */
    public function rememberAppId(?string $app_id): void
    {
        $setting = $this->activeSetting();

        if ($setting && ! filled($setting->app_id) && filled($app_id) && $app_id !== $this->environmentValue('app_id')) {
            $setting->forceFill(['app_id' => $app_id])->save();
        }
    }

    /**
     * Forgets a saved app. When it was in use, the environment app takes over (or the first
     * remaining saved app when the environment has none). Connected workspaces keep their bot tokens.
     */
    public function remove(SlackAppSetting $setting): void
    {
        $setting->delete();
    }

    /**
     * What the administration page shows: the app in use, plus every app that can be selected.
     * Never a secret, only whether one is set and its last four characters, so an administrator
     * can tell which value is saved.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $setting = $this->activeSetting();
        $app_id = $this->appId();
        $active_key = $this->activeKey();

        return [
            'source' => $this->source(),
            'active_key' => $active_key,
            'is_configured' => $this->isConfigured(),
            'app_name' => $this->appName(),
            'app_id' => $app_id,
            'app_settings_url' => $this->appSettingsUrl($app_id),
            // Where public distribution is turned on, needed to add the app to more than one workspace.
            'distribution_url' => $app_id ? "https://api.slack.com/apps/{$app_id}/distribute" : null,
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
            'apps' => [
                $this->describeEnvironmentApp($active_key),
                ...SlackAppSetting::query()->with('updatedBy')->oldest('id')->get()
                    ->map(fn (SlackAppSetting $saved) => $this->describeSavedApp($saved, $active_key))
                    ->all(),
            ],
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

    /**
     * @return array<string, mixed>
     */
    private function describeEnvironmentApp(?string $active_key): array
    {
        $app_id = $this->environmentValue('app_id');
        $redirect_uri = $this->environmentValue('redirect_uri');

        return [
            'key' => self::ENVIRONMENT_APP_KEY,
            'id' => null,
            'source' => self::SOURCE_ENVIRONMENT,
            'name' => $this->environmentAppName(),
            'label' => null,
            'is_configured' => $this->isEnvironmentConfigured(),
            'is_active' => $active_key === self::ENVIRONMENT_APP_KEY,
            // Set in the API .env file, it cannot be changed or removed from the site.
            'is_editable' => false,
            'app_id' => $app_id,
            'app_settings_url' => $this->appSettingsUrl($app_id),
            'client_id' => $this->environmentValue('client_id'),
            'client_secret_hint' => $this->hint($this->environmentValue('client_secret')),
            'signing_secret_hint' => $this->hint($this->environmentValue('signing_secret')),
            'has_verification_token' => filled($this->environmentValue('verification_token')),
            'redirect_uri' => filled($redirect_uri) ? $redirect_uri : $this->defaultRedirectUri(),
            'redirect_uri_override' => $redirect_uri,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSavedApp(SlackAppSetting $setting, ?string $active_key): array
    {
        return [
            'key' => (string) $setting->id,
            'id' => $setting->id,
            'source' => self::SOURCE_DATABASE,
            'name' => $this->settingName($setting),
            'label' => $setting->label,
            'is_configured' => filled($setting->client_id) && filled($setting->client_secret),
            'is_active' => $active_key === (string) $setting->id,
            'is_editable' => true,
            'app_id' => $setting->app_id,
            'app_settings_url' => $this->appSettingsUrl($setting->app_id),
            'client_id' => $setting->client_id,
            'client_secret_hint' => $this->hint($setting->client_secret),
            'signing_secret_hint' => $this->hint($setting->signing_secret),
            'has_verification_token' => false,
            'redirect_uri' => $setting->redirect_uri ?: $this->defaultRedirectUri(),
            'redirect_uri_override' => $setting->redirect_uri,
            'updated_at' => $setting->updated_at,
            'updated_by' => $setting->updatedBy?->full_name,
        ];
    }

    /**
     * A value of the app in use, null when it is not set.
     */
    private function activeValue(string $key): ?string
    {
        $value = match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue($key),
            self::SOURCE_DATABASE => $this->activeSetting()?->getAttribute($key),
            default => null,
        };

        return filled($value) ? (string) $value : null;
    }

    private function environmentAppName(): string
    {
        return (string) (config('services.slack.app_name') ?: '97th Floor');
    }

    private function settingName(?SlackAppSetting $setting): string
    {
        return $setting?->label ?: 'Slack app '.Str::before((string) $setting?->client_id, '.');
    }

    private function environmentValue(string $key): ?string
    {
        $value = config("services.slack.{$key}");

        return filled($value) ? (string) $value : null;
    }

    private function appSettingsUrl(?string $app_id): ?string
    {
        return $app_id ? "https://api.slack.com/apps/{$app_id}" : null;
    }

    private function hint(?string $secret): ?string
    {
        return $secret ? '••••'.substr($secret, -4) : null;
    }
}
