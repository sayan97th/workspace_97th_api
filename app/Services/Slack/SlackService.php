<?php

namespace App\Services\Slack;

use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of the Slack connection, modeled on monday.com's Connections page: an
 * administrator adds one or more Slack workspaces through "Add to Slack" and picks which one
 * is active, members are matched to their Slack account by email or link it themselves through
 * "Connect my Slack", and either side can disconnect. Also reads the channel list.
 *
 * Both OAuth flows share one redirect URI and are told apart by the single use `state`
 * value issued here, which is bound to the user who started the flow. That is what lets the
 * public callback route trust who it is acting for without a JWT, the browser arrives from
 * Slack with no `Authorization` header.
 */
class SlackService
{
    /**
     * Scopes requested for the app's bot when an administrator installs it. `users:read` and
     * `users:read.email` let the app match members to their Slack account by email, the history,
     * reactions, files and app mention scopes are used by the notification test suite.
     */
    public const BOT_SCOPES = [
        'chat:write', 'chat:write.public', 'channels:read', 'groups:read', 'users:read', 'users:read.email',
        'channels:history', 'groups:history', 'reactions:read', 'reactions:write', 'files:read', 'files:write', 'app_mentions:read',
    ];

    /**
     * Bot scopes only "match members by email" and the notification test suite need, a workspace
     * installed without them still delivers every notification.
     */
    public const OPTIONAL_BOT_SCOPES = [
        'users:read', 'users:read.email',
        'channels:history', 'groups:history', 'reactions:read', 'reactions:write', 'files:read', 'files:write', 'app_mentions:read',
    ];

    /** Scopes requested when a member proves which Slack account is theirs. */
    public const USER_SCOPES = ['openid', 'profile', 'email'];

    public const PURPOSE_INSTALL = 'install';

    public const PURPOSE_LINK = 'link';

    /** The authorization was opened in its own browser tab, which reports back and closes. */
    public const DISPLAY_TAB = 'tab';

    /** The authorization took over the current tab, which is sent back to `return_path`. */
    public const DISPLAY_PAGE = 'page';

    private const STATE_TTL_MINUTES = 10;

    private const CHANNEL_CACHE_SECONDS = 60;

    /** 200 channels per page, so up to 10,000 channels, more than any workspace we serve. */
    private const CHANNEL_PAGE_LIMIT = 50;

    /** Same bound for members, 200 per page. */
    private const MEMBER_PAGE_LIMIT = 50;

    /** How long a rate limited page may wait before retrying, longer waits fail the request instead. */
    private const RATE_LIMIT_MAX_WAIT_SECONDS = 5;

    private const RATE_LIMIT_MAX_RETRIES = 3;

    public function __construct(
        private readonly SlackClient $client,
        private readonly SlackAppCredentials $credentials,
    ) {}

    /**
     * Whether the Slack app's credentials were saved in Administration.
     */
    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }

    public function installation(): ?SlackInstallation
    {
        return SlackInstallation::current();
    }

    /**
     * Every connected workspace, the active one first, then the newest.
     *
     * @return EloquentCollection<int, SlackInstallation>
     */
    public function installations(): EloquentCollection
    {
        return SlackInstallation::query()
            ->with('installedBy')
            ->withCount(['userLinks' => fn ($query) => $query->whereHas('user')])
            ->orderByDesc('is_active')
            ->latest('id')
            ->get();
    }

    /**
     * The Slack "Add to Slack" URL an administrator is sent to. Slack shows its own workspace
     * picker in the top right corner of that page, which is how a different workspace is added.
     *
     * @throws SlackException
     */
    public function buildInstallUrl(User $actor, ?string $return_path = null, string $display = self::DISPLAY_PAGE): string
    {
        $this->ensureConfigured();

        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => $this->credentials->clientId(),
            'scope' => implode(',', self::BOT_SCOPES),
            'redirect_uri' => $this->credentials->redirectUri(),
            'state' => $this->issueState(self::PURPOSE_INSTALL, $actor, $return_path, $display),
        ]);
    }

    /**
     * The "Sign in with Slack" URL a member is sent to, pinned to the active workspace.
     *
     * Slack's OpenID flow only accepts an HTTPS redirect URL, unlike "Add to Slack", and answers
     * a plain http one (a local API) with its own "invalid redirect_uri" error page. That case is
     * refused here instead, so the member gets a message that says what to do.
     *
     * @throws SlackException
     */
    public function buildLinkUrl(User $user, ?string $return_path = null, string $display = self::DISPLAY_PAGE): string
    {
        $this->ensureConfigured();
        $installation = $this->requireInstallation();

        if (parse_url($this->credentials->redirectUri(), PHP_URL_SCHEME) !== 'https') {
            throw new SlackException('link_requires_https', 'Sign in with Slack needs an HTTPS redirect URL.');
        }

        return 'https://slack.com/openid/connect/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->credentials->clientId(),
            'scope' => implode(' ', self::USER_SCOPES),
            'redirect_uri' => $this->credentials->redirectUri(),
            'team' => $installation->team_id,
            'state' => $this->issueState(self::PURPOSE_LINK, $user, $return_path, $display),
        ]);
    }

    /**
     * Redeems a `state` value once. Returns the purpose, the user it was issued for, the
     * in-app path to send the browser back to, if one was asked for, and how the flow was opened.
     *
     * @return array{purpose: string, user: User, return_path: string|null, display: string}
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

        return [
            'purpose' => $payload['purpose'],
            'user' => $user,
            'return_path' => $payload['return_path'] ?? null,
            'display' => $payload['display'] ?? self::DISPLAY_PAGE,
        ];
    }

    /**
     * Finishes the "Add to Slack" flow, storing (or refreshing) the workspace's bot token. The
     * workspace just added becomes the active one, the others stay connected so an administrator
     * can switch back without authorizing again.
     *
     * @throws SlackException
     */
    public function completeInstall(string $code, User $actor): SlackInstallation
    {
        $payload = $this->client->exchangeInstallCode($code, $this->credentials->redirectUri());

        $team_id = $payload['team']['id'] ?? null;
        $bot_token = $payload['access_token'] ?? null;

        if (! is_string($team_id) || ! is_string($bot_token)) {
            throw new SlackException('invalid_response', 'Slack did not return a workspace and token.');
        }

        $installation = SlackInstallation::query()->where('team_id', $team_id)->first();

        // Reinstalling with a new token, the old one may already be revoked by Slack.
        if ($installation && $installation->bot_token !== $bot_token) {
            $this->revokeQuietly($installation);
        }

        $installation = SlackInstallation::updateOrCreate(
            ['team_id' => $team_id],
            [
                'team_name' => (string) ($payload['team']['name'] ?? $team_id),
                'team_url' => $this->fetchTeamUrl($bot_token),
                'bot_user_id' => $payload['bot_user_id'] ?? null,
                'app_id' => $payload['app_id'] ?? null,
                'bot_token' => $bot_token,
                'scopes' => $payload['scope'] ?? null,
                'installed_by_id' => $actor->id,
                'is_active' => false,
            ],
        );

        return $this->activate($installation);
    }

    /**
     * Makes `$installation` the workspace notifications and automations use.
     */
    public function activate(SlackInstallation $installation): SlackInstallation
    {
        DB::transaction(function () use ($installation) {
            SlackInstallation::query()->whereKeyNot($installation->id)->where('is_active', true)->update(['is_active' => false]);
            $installation->forceFill(['is_active' => true])->save();
        });

        $this->forgetChannels($installation);

        return $installation->refresh();
    }

    /**
     * Finishes the "Connect my Slack" flow for `$user`.
     *
     * @throws SlackException
     */
    public function completeLink(string $code, User $user): SlackUserLink
    {
        $installation = $this->requireInstallation();

        $token_payload = $this->client->exchangeUserCode($code, $this->credentials->redirectUri());
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
            ['user_id' => $user->id, 'slack_installation_id' => $installation->id],
            [
                'slack_user_id' => $slack_user_id,
                'slack_display_name' => $identity['name'] ?? null,
                'linked_at' => now(),
            ],
        );
    }

    /**
     * Links every active member whose email matches a Slack member of `$installation`, so they
     * receive Slack notifications without each one signing in to Slack first. A link a member
     * made themselves is never replaced, and a Slack account already linked to someone else is
     * never linked twice.
     *
     * @return array{matched: int, already_linked: int, unmatched: int}
     *
     * @throws SlackException
     */
    public function matchMembersByEmail(SlackInstallation $installation): array
    {
        if (! $installation->hasScope('users:read.email')) {
            throw new SlackException('missing_scope', 'The Slack app needs the users:read.email permission to match members by email.');
        }

        $slack_members_by_email = $this->fetchMembersByEmail($installation);
        $existing_links = $installation->userLinks()->get(['user_id', 'slack_user_id']);
        $linked_user_ids = $existing_links->pluck('user_id')->flip();
        $taken_slack_ids = $existing_links->pluck('slack_user_id')->flip();

        $matched = 0;
        $already_linked = 0;
        $unmatched = 0;

        User::query()->where('is_active', true)->select(['id', 'email'])->chunkById(500, function ($users) use (
            $installation, $slack_members_by_email, $linked_user_ids, &$taken_slack_ids, &$matched, &$already_linked, &$unmatched
        ) {
            foreach ($users as $user) {
                if ($linked_user_ids->has($user->id)) {
                    $already_linked++;

                    continue;
                }

                $member = $slack_members_by_email[mb_strtolower((string) $user->email)] ?? null;

                if (! $member || $taken_slack_ids->has($member['id'])) {
                    $unmatched++;

                    continue;
                }

                SlackUserLink::create([
                    'user_id' => $user->id,
                    'slack_installation_id' => $installation->id,
                    'slack_user_id' => $member['id'],
                    'slack_display_name' => $member['name'],
                    'linked_at' => now(),
                ]);

                $taken_slack_ids->put($member['id'], true);
                $matched++;
            }
        });

        return ['matched' => $matched, 'already_linked' => $already_linked, 'unmatched' => $unmatched];
    }

    /**
     * Disconnects one workspace: revokes its bot token, then removes it together with every
     * member link made against it. When it was the active workspace the most recently added
     * remaining one takes over, which is returned, or null when no workspace is left.
     */
    public function disconnect(SlackInstallation $installation): ?SlackInstallation
    {
        $this->revokeQuietly($installation);

        return $this->removeInstallation($installation);
    }

    /**
     * Called when Slack reports the bot token is no longer valid (an admin removed the app
     * from their workspace), so the UI stops claiming that workspace is connected.
     */
    public function handleRevokedInstallation(SlackInstallation $installation): void
    {
        $this->removeInstallation($installation);
    }

    /**
     * Removes `$user`'s Slack account from the active workspace only, links to other
     * workspaces stay for when an administrator switches back.
     */
    public function unlinkUser(User $user): void
    {
        $installation = $this->installation();

        if ($installation) {
            SlackUserLink::where('user_id', $user->id)->where('slack_installation_id', $installation->id)->delete();
        }
    }

    /**
     * Channels the bot can post to, alphabetical, cached briefly since the automation
     * picker asks for them every time it opens. `$fresh` skips the cache, for the picker's
     * "Refresh" button and for diagnostics, so a channel created or joined a moment ago shows.
     *
     * With a bot token Slack returns every public channel, but only the private channels the
     * app was invited to. That is also exactly where the bot is allowed to post.
     *
     * @return array<int, array{id: string, name: string, is_private: bool}>
     *
     * @throws SlackException
     */
    public function listChannels(SlackInstallation $installation, bool $fresh = false): array
    {
        $cache_key = $this->channelCacheKey($installation);

        if ($fresh) {
            Cache::forget($cache_key);
        }

        return Cache::remember($cache_key, self::CHANNEL_CACHE_SECONDS, fn () => $this->fetchAllChannels($installation));
    }

    /**
     * Walks every page of `conversations.list`. Slack may return fewer channels than the
     * page limit, and even an empty page, while more remain, so only a missing cursor ends
     * the walk. A rate limited page is retried after the wait Slack asks for, so one busy
     * moment does not hide every channel after the first pages.
     *
     * @return array<int, array{id: string, name: string, is_private: bool}>
     *
     * @throws SlackException
     */
    private function fetchAllChannels(SlackInstallation $installation): array
    {
        $channels_by_id = [];
        $cursor = null;
        $retries_left = self::RATE_LIMIT_MAX_RETRIES;

        for ($page = 0; $page < self::CHANNEL_PAGE_LIMIT; $page++) {
            try {
                $payload = $this->client->listChannels($installation->bot_token, $cursor);
            } catch (SlackException $exception) {
                $wait_seconds = $exception->retry_after ?? self::RATE_LIMIT_MAX_WAIT_SECONDS;

                if (! $exception->isRateLimited() || $retries_left === 0 || $wait_seconds > self::RATE_LIMIT_MAX_WAIT_SECONDS) {
                    throw $exception;
                }

                $retries_left--;
                $page--;
                Sleep::for(max(1, $wait_seconds))->seconds();

                continue;
            }

            foreach ($payload['channels'] ?? [] as $channel) {
                $channel_id = (string) $channel['id'];

                // Keyed by id, a channel created while paging can shift into the next page twice.
                $channels_by_id[$channel_id] = [
                    'id' => $channel_id,
                    'name' => (string) ($channel['name'] ?? $channel_id),
                    'is_private' => (bool) ($channel['is_private'] ?? false),
                ];
            }

            $cursor = $payload['response_metadata']['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        if ($cursor) {
            Log::warning('Slack channel list truncated after the page limit.', ['installation_id' => $installation->id, 'channel_count' => count($channels_by_id)]);
        }

        $channels = array_values($channels_by_id);
        usort($channels, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $channels;
    }

    /**
     * Every Slack member with an email address, keyed by the lowercased email. Bots, Slackbot
     * and deactivated members are left out, a message to them would never be read.
     *
     * @return array<string, array{id: string, name: string|null}>
     *
     * @throws SlackException
     */
    private function fetchMembersByEmail(SlackInstallation $installation): array
    {
        $members_by_email = [];
        $cursor = null;
        $retries_left = self::RATE_LIMIT_MAX_RETRIES;

        for ($page = 0; $page < self::MEMBER_PAGE_LIMIT; $page++) {
            try {
                $payload = $this->client->listUsers($installation->bot_token, $cursor);
            } catch (SlackException $exception) {
                $wait_seconds = $exception->retry_after ?? self::RATE_LIMIT_MAX_WAIT_SECONDS;

                if (! $exception->isRateLimited() || $retries_left === 0 || $wait_seconds > self::RATE_LIMIT_MAX_WAIT_SECONDS) {
                    throw $exception;
                }

                $retries_left--;
                $page--;
                Sleep::for(max(1, $wait_seconds))->seconds();

                continue;
            }

            foreach ($payload['members'] ?? [] as $member) {
                $email = $member['profile']['email'] ?? null;

                if (! is_string($email) || $email === '' || ($member['deleted'] ?? false) || ($member['is_bot'] ?? false) || ($member['id'] ?? '') === 'USLACKBOT') {
                    continue;
                }

                $display_name = $member['profile']['real_name'] ?? $member['real_name'] ?? $member['name'] ?? null;

                $members_by_email[mb_strtolower($email)] = [
                    'id' => (string) $member['id'],
                    'name' => is_string($display_name) && $display_name !== '' ? $display_name : null,
                ];
            }

            $cursor = $payload['response_metadata']['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $members_by_email;
    }

    /**
     * The workspace's own address, for the "Open in Slack" link. Best effort, a missing URL
     * only hides that link.
     */
    private function fetchTeamUrl(string $bot_token): ?string
    {
        try {
            $url = $this->client->authTest($bot_token)['url'] ?? null;
        } catch (SlackException) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function removeInstallation(SlackInstallation $installation): ?SlackInstallation
    {
        $was_active = $installation->is_active;

        $this->forgetChannels($installation);
        $installation->delete();

        $current = SlackInstallation::current();

        if (! $current && $was_active && $next = SlackInstallation::query()->latest('id')->first()) {
            return $this->activate($next);
        }

        return $current;
    }

    private function issueState(string $purpose, User $user, ?string $return_path, string $display): string
    {
        $state = Str::random(40);

        Cache::put(
            $this->stateCacheKey($state),
            ['purpose' => $purpose, 'user_id' => $user->id, 'return_path' => $return_path, 'display' => $display],
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

    private function forgetChannels(SlackInstallation $installation): void
    {
        Cache::forget($this->channelCacheKey($installation));
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
