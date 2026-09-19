<?php

namespace App\Services\Slack;

use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of the Slack connection: the administrator's "Add to Slack" install,
 * every member's own "Connect my Slack" link, disconnecting, and reading the channel list.
 *
 * Both OAuth flows share one redirect URI and are told apart by the single use `state`
 * value issued here, which is bound to the user who started the flow. That is what lets the
 * public callback route trust who it is acting for without a JWT, the browser arrives from
 * Slack with no `Authorization` header.
 */
class SlackService
{
    /** Scopes requested for the app's bot when an administrator installs it. */
    public const BOT_SCOPES = ['chat:write', 'chat:write.public', 'channels:read', 'groups:read'];

    /** Scopes requested when a member proves which Slack account is theirs. */
    public const USER_SCOPES = ['openid', 'profile', 'email'];

    public const PURPOSE_INSTALL = 'install';

    public const PURPOSE_LINK = 'link';

    private const STATE_TTL_MINUTES = 10;

    private const CHANNEL_CACHE_SECONDS = 60;

    private const CHANNEL_PAGE_LIMIT = 10;

    public function __construct(private readonly SlackClient $client) {}

    /**
     * Whether the Slack app's credentials are present in the environment.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.slack.client_id')) && filled(config('services.slack.client_secret'));
    }

    public function installation(): ?SlackInstallation
    {
        return SlackInstallation::current();
    }

    /**
     * The Slack "Add to Slack" URL an administrator is sent to.
     *
     * @throws SlackException
     */
    public function buildInstallUrl(User $actor, ?string $return_path = null): string
    {
        $this->ensureConfigured();

        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => config('services.slack.client_id'),
            'scope' => implode(',', self::BOT_SCOPES),
            'redirect_uri' => config('services.slack.redirect'),
            'state' => $this->issueState(self::PURPOSE_INSTALL, $actor, $return_path),
        ]);
    }

    /**
     * The "Sign in with Slack" URL a member is sent to, pinned to the installed workspace.
     *
     * @throws SlackException
     */
    public function buildLinkUrl(User $user, ?string $return_path = null): string
    {
        $this->ensureConfigured();
        $installation = $this->requireInstallation();

        return 'https://slack.com/openid/connect/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.slack.client_id'),
            'scope' => implode(' ', self::USER_SCOPES),
            'redirect_uri' => config('services.slack.redirect'),
            'team' => $installation->team_id,
            'state' => $this->issueState(self::PURPOSE_LINK, $user, $return_path),
        ]);
    }

    /**
     * Redeems a `state` value once. Returns the purpose, the user it was issued for and
     * the in-app path to send the browser back to, if one was asked for.
     *
     * @return array{purpose: string, user: User, return_path: string|null}
     *
     * @throws SlackException
     */
    public function consumeState(?string $state): array
    {
        $payload = $state ? Cache::pull($this->stateCacheKey($state)) : null;
        $user = is_array($payload) && isset($payload['user_id']) ? User::query()->whereKey((int) $payload['user_id'])->first() : null;

        if (! $user || ! $user->is_active) {
            throw new SlackException('invalid_state', 'The Slack connection request expired. Please try again.');
        }

        return ['purpose' => $payload['purpose'], 'user' => $user, 'return_path' => $payload['return_path'] ?? null];
    }

    /**
     * Finishes the "Add to Slack" flow, storing (or refreshing) the workspace's bot token.
     *
     * @throws SlackException
     */
    public function completeInstall(string $code, User $actor): SlackInstallation
    {
        $payload = $this->client->exchangeInstallCode($code, (string) config('services.slack.redirect'));

        $team_id = $payload['team']['id'] ?? null;
        $bot_token = $payload['access_token'] ?? null;

        if (! is_string($team_id) || ! is_string($bot_token)) {
            throw new SlackException('invalid_response', 'Slack did not return a workspace and token.');
        }

        return DB::transaction(function () use ($payload, $team_id, $bot_token, $actor) {
            $existing = SlackInstallation::current();

            // The app is single tenant, so switching Slack workspaces replaces the old
            // installation and, through the cascade, every member link made against it.
            if ($existing && $existing->team_id !== $team_id) {
                $this->revokeQuietly($existing);
                $existing->delete();
            }

            $this->forgetChannels();

            return SlackInstallation::updateOrCreate(
                ['team_id' => $team_id],
                [
                    'team_name' => (string) ($payload['team']['name'] ?? $team_id),
                    'bot_user_id' => $payload['bot_user_id'] ?? null,
                    'bot_token' => $bot_token,
                    'scopes' => $payload['scope'] ?? null,
                    'installed_by_id' => $actor->id,
                ],
            );
        });
    }

    /**
     * Finishes the "Connect my Slack" flow for `$user`.
     *
     * @throws SlackException
     */
    public function completeLink(string $code, User $user): SlackUserLink
    {
        $installation = $this->requireInstallation();

        $token_payload = $this->client->exchangeUserCode($code, (string) config('services.slack.redirect'));
        $access_token = $token_payload['access_token'] ?? null;

        if (! is_string($access_token)) {
            throw new SlackException('invalid_response', 'Slack did not return an access token.');
        }

        $identity = $this->client->fetchUserInfo($access_token);
        $slack_user_id = $identity['sub'] ?? null;

        if (! is_string($slack_user_id) || $slack_user_id === '') {
            throw new SlackException('invalid_response', 'Slack did not return a member id.');
        }

        if (($identity['https://slack.com/team_id'] ?? null) !== $installation->team_id) {
            throw new SlackException('wrong_workspace', "That Slack account is not part of {$installation->team_name}.");
        }

        return SlackUserLink::updateOrCreate(
            ['user_id' => $user->id],
            [
                'slack_installation_id' => $installation->id,
                'slack_user_id' => $slack_user_id,
                'slack_display_name' => $identity['name'] ?? null,
                'linked_at' => now(),
            ],
        );
    }

    /**
     * Disconnects the workspace: revokes the bot token, then removes the installation
     * together with every member link.
     */
    public function disconnect(SlackInstallation $installation): void
    {
        $this->revokeQuietly($installation);
        $installation->delete();
        $this->forgetChannels();
    }

    /**
     * Called when Slack reports the bot token is no longer valid (an admin removed the app
     * from their workspace), so the UI stops claiming Slack is connected.
     */
    public function handleRevokedInstallation(SlackInstallation $installation): void
    {
        $installation->delete();
        $this->forgetChannels();
    }

    public function unlinkUser(User $user): void
    {
        SlackUserLink::where('user_id', $user->id)->delete();
    }

    /**
     * Channels the bot can post to, alphabetical, cached briefly since the automation
     * picker asks for them every time it opens.
     *
     * @return array<int, array{id: string, name: string, is_private: bool}>
     *
     * @throws SlackException
     */
    public function listChannels(SlackInstallation $installation): array
    {
        return Cache::remember($this->channelCacheKey($installation), self::CHANNEL_CACHE_SECONDS, function () use ($installation) {
            $channels = [];
            $cursor = null;

            for ($page = 0; $page < self::CHANNEL_PAGE_LIMIT; $page++) {
                $payload = $this->client->listChannels($installation->bot_token, $cursor);

                foreach ($payload['channels'] ?? [] as $channel) {
                    $channels[] = [
                        'id' => (string) $channel['id'],
                        'name' => (string) ($channel['name'] ?? $channel['id']),
                        'is_private' => (bool) ($channel['is_private'] ?? false),
                    ];
                }

                $cursor = $payload['response_metadata']['next_cursor'] ?? null;
                if (! $cursor) {
                    break;
                }
            }

            usort($channels, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

            return $channels;
        });
    }

    private function issueState(string $purpose, User $user, ?string $return_path = null): string
    {
        $state = Str::random(40);

        Cache::put(
            $this->stateCacheKey($state),
            ['purpose' => $purpose, 'user_id' => $user->id, 'return_path' => $return_path],
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        return $state;
    }

    /**
     * @throws SlackException
     */
    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SlackException('not_configured', 'Slack is not configured on this server yet.');
        }
    }

    /**
     * @throws SlackException
     */
    private function requireInstallation(): SlackInstallation
    {
        return $this->installation() ?? throw new SlackException('not_installed', 'Slack is not connected to this account yet.');
    }

    private function revokeQuietly(SlackInstallation $installation): void
    {
        try {
            $this->client->revokeToken($installation->bot_token);
        } catch (SlackException) {
            // The token may already be revoked, removing our copy is what matters.
        }
    }

    private function forgetChannels(): void
    {
        if ($installation = SlackInstallation::current()) {
            Cache::forget($this->channelCacheKey($installation));
        }
    }

    private function stateCacheKey(string $state): string
    {
        return "slack_oauth_state:{$state}";
    }

    private function channelCacheKey(SlackInstallation $installation): string
    {
        return "slack_channels:{$installation->id}";
    }
}
