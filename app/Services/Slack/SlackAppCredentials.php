<?php

namespace App\Services\Slack;

use App\Models\SlackAppSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Which Slack app the integration uses, and its credentials. There are two:
 *
 * - The production app, the 97th Floor Slack app, read from the API environment (the SLACK_*
 *   variables in `config/services.php`). It is the default whenever it is set.
 * - An optional testing app, for example the Make It Simple app, saved by an administrator from
 *   Administration > Integrations > Slack, so tests never reach the 97th Floor workspace. It is
 *   only used while an administrator switched to it, or when the environment has no app.
 *
 * Values of the two apps are never mixed, every credential comes from the app in use.
 */
class SlackAppCredentials
{
    /** Where the callback route lives, relative to APP_URL. */
    private const CALLBACK_PATH = '/api/integrations/slack/callback';

    /** The production app, from the API environment. */
    public const SOURCE_ENVIRONMENT = 'environment';

    /** The testing app, saved from Administration. */
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_NONE = 'none';

    /** The apps an administrator can switch between. */
    public const SELECTABLE_SOURCES = [self::SOURCE_ENVIRONMENT, self::SOURCE_DATABASE];

    private const DEFAULT_TESTING_APP_NAME = 'Testing';

    /**
     * The app in use: the testing app while an administrator switched to it (or when it is the
     * only one), otherwise the production app from the environment.
     */
    public function source(): string
    {
        $setting = $this->setting();
        $is_environment_configured = $this->isEnvironmentConfigured();

        if ($setting && ($setting->is_active || ! $is_environment_configured)) {
            return self::SOURCE_DATABASE;
        }

        return $is_environment_configured ? self::SOURCE_ENVIRONMENT : self::SOURCE_NONE;
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    /**
     * Whether the production app's client id and secret are set in the API environment.
     */
    public function isEnvironmentConfigured(): bool
    {
        return filled($this->environmentValue('client_id')) && filled($this->environmentValue('client_secret'));
    }

    public function isUsingTestingApp(): bool
    {
        return $this->source() === self::SOURCE_DATABASE;
    }

    public function clientId(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue('client_id'),
            self::SOURCE_DATABASE => $this->setting()?->client_id,
            default => null,
        };
    }

    public function clientSecret(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue('client_secret'),
            self::SOURCE_DATABASE => $this->setting()?->client_secret,
            default => null,
        };
    }

    public function signingSecret(): ?string
    {
        $signing_secret = match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue('signing_secret'),
            self::SOURCE_DATABASE => $this->setting()?->signing_secret,
            default => null,
        };

        return filled($signing_secret) ? $signing_secret : null;
    }

    /**
     * Every signing secret Slack may sign a request with, the app in use first. Both apps keep
     * sending events to their own workspaces (an uninstall, a revoked token) while the other one
     * is in use, so a request signed by either app is genuine.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $signing_secrets = [$this->signingSecret(), $this->environmentValue('signing_secret'), $this->setting()?->signing_secret];

        return array_values(array_unique(array_filter($signing_secrets, fn (?string $secret) => filled($secret))));
    }

    /**
     * The Slack app id of the app in use, known for the production app (SLACK_APP_ID), for a
     * testing app the site created, and for one that was already installed into a workspace.
     */
    public function appId(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue('app_id'),
            self::SOURCE_DATABASE => $this->setting()?->app_id,
            default => null,
        };
    }

    /**
     * The name shown for the app in use, "97th Floor" or the testing app's label.
     */
    public function appName(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentAppName(),
            self::SOURCE_DATABASE => $this->testingAppName(),
            default => null,
        };
    }

    /**
     * Which of the two apps a workspace was installed with, from the app id Slack returned at
     * install time. Null when it matches neither, for example an app that was replaced.
     */
    public function sourceOfAppId(?string $app_id): ?string
    {
        if (! filled($app_id)) {
            return null;
        }

        return match ($app_id) {
            $this->environmentValue('app_id') => self::SOURCE_ENVIRONMENT,
            $this->setting()?->app_id => self::SOURCE_DATABASE,
            default => null,
        };
    }

    /**
     * The OAuth redirect URL registered in the Slack app in use. An override (SLACK_REDIRECT_URI,
     * or the testing app's saved one) is useful behind an HTTPS tunnel, otherwise it is the API's
     * own callback under APP_URL.
     */
    public function redirectUri(): string
    {
        $override = match ($this->source()) {
            self::SOURCE_ENVIRONMENT => $this->environmentValue('redirect_uri'),
            default => $this->setting()?->redirect_uri,
        };

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
     * Saves the testing app. A blank secret keeps the saved one, so an administrator can change
     * the redirect URL without typing both secrets again, but only while the client id stays the
     * same, a different client id is a different Slack app with its own secrets.
     *
     * Saving never switches to the testing app, that is {@see useApp()}, so production keeps
     * using the 97th Floor app until an administrator switches on purpose.
     *
     * @param  array{label?: string|null, client_id: string, client_secret?: string|null, signing_secret?: string|null, redirect_uri?: string|null}  $values
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
            'label' => filled($values['label'] ?? null) ? trim($values['label']) : null,
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
     * Saves an app the site just created in Slack as the testing app, replacing any previous
     * one. The redirect URL override, the label and whether it is in use are kept, they describe
     * how this site uses a testing app, not the app itself.
     */
    public function storeCreatedApp(string $app_id, string $client_id, string $client_secret, ?string $signing_secret, User $actor): SlackAppSetting
    {
        $previous = $this->setting();

        SlackAppSetting::query()->delete();

        return SlackAppSetting::create([
            'app_id' => $app_id,
            'label' => $previous?->label,
            'is_active' => $previous?->is_active ?? false,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'signing_secret' => $signing_secret,
            'redirect_uri' => $previous?->redirect_uri,
            'updated_by_id' => $actor->id,
        ]);
    }

    /**
     * Switches the integration to the production app (`environment`) or the testing app
     * (`database`). Connected workspaces stay connected, see {@see SlackService::useApp()} for
     * how the active workspace follows the app.
     *
     * @throws SlackException
     */
    public function useApp(string $source, User $actor): void
    {
        $setting = $this->setting();

        if ($source === self::SOURCE_ENVIRONMENT && ! $this->isEnvironmentConfigured()) {
            throw new SlackException('environment_app_missing', "The {$this->environmentAppName()} Slack app is not set on the server. Add SLACK_CLIENT_ID and SLACK_CLIENT_SECRET to the API .env file.");
        }

        if ($source === self::SOURCE_DATABASE && ! $setting) {
            throw new SlackException('testing_app_missing', 'Save the testing Slack app credentials first.');
        }

        $setting?->forceFill(['is_active' => $source === self::SOURCE_DATABASE, 'updated_by_id' => $actor->id])->save();
    }

    /**
     * Remembers the testing app's id the first time Slack returns it, during an install, so its
     * workspaces can be told apart from the production app's ones.
     */
    public function rememberAppId(?string $app_id): void
    {
        $setting = $this->setting();

        if ($this->isUsingTestingApp() && $setting && ! filled($setting->app_id) && filled($app_id) && $app_id !== $this->environmentValue('app_id')) {
            $setting->forceFill(['app_id' => $app_id])->save();
        }
    }

    /**
     * Forgets the testing app, the production app from the environment takes over. Connected
     * workspaces keep their bot tokens.
     */
    public function clear(): void
    {
        SlackAppSetting::query()->delete();
    }

    /**
     * What the administration page shows: the app in use, plus a summary of each of the two
     * apps. Never a secret, only whether one is set and its last four characters, so an
     * administrator can tell which value is saved.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $setting = $this->setting();
        $app_id = $this->appId();

        return [
            'source' => $this->source(),
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
            'environment_app' => $this->describeEnvironmentApp(),
            'testing_app' => $setting ? $this->describeTestingApp($setting) : null,
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
    private function describeEnvironmentApp(): array
    {
        $app_id = $this->environmentValue('app_id');
        $redirect_uri = $this->environmentValue('redirect_uri');

        return [
            'name' => $this->environmentAppName(),
            'is_configured' => $this->isEnvironmentConfigured(),
            'is_active' => $this->source() === self::SOURCE_ENVIRONMENT,
            'app_id' => $app_id,
            'app_settings_url' => $this->appSettingsUrl($app_id),
            'client_id' => $this->environmentValue('client_id'),
            'client_secret_hint' => $this->hint($this->environmentValue('client_secret')),
            'signing_secret_hint' => $this->hint($this->environmentValue('signing_secret')),
            'has_verification_token' => filled($this->environmentValue('verification_token')),
            'redirect_uri' => filled($redirect_uri) ? $redirect_uri : $this->defaultRedirectUri(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTestingApp(SlackAppSetting $setting): array
    {
        return [
            'name' => $this->testingAppName(),
            'label' => $setting->label,
            'is_active' => $this->source() === self::SOURCE_DATABASE,
            'app_id' => $setting->app_id,
            'app_settings_url' => $this->appSettingsUrl($setting->app_id),
            'client_id' => $setting->client_id,
            'client_secret_hint' => $this->hint($setting->client_secret),
            'signing_secret_hint' => $this->hint($setting->signing_secret),
            'redirect_uri' => $setting->redirect_uri ?: $this->defaultRedirectUri(),
            'updated_at' => $setting->updated_at,
            'updated_by' => $setting->updatedBy?->full_name,
        ];
    }

    private function environmentAppName(): string
    {
        return (string) (config('services.slack.app_name') ?: '97th Floor');
    }

    private function testingAppName(): string
    {
        return $this->setting()?->label ?: self::DEFAULT_TESTING_APP_NAME;
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

    private function setting(): ?SlackAppSetting
    {
        return SlackAppSetting::current();
    }

    private function hint(?string $secret): ?string
    {
        return $secret ? '••••'.substr($secret, -4) : null;
    }
}
