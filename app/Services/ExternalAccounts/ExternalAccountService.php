<?php

namespace App\Services\ExternalAccounts;

use App\Enums\ExternalProvider;
use App\Enums\ExternalService;
use App\Models\BoardAutomation;
use App\Models\ExternalAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A member's own Google and Microsoft accounts, the "Connect your Gmail account" step of the
 * Automations center. Connecting opens the provider's consent page in a new tab, the provider
 * sends the browser back to one public callback, and who it was for comes from a single use
 * `state` value, the same way the Slack flows work.
 */
class ExternalAccountService
{
    public const DISPLAY_TAB = 'tab';

    public const DISPLAY_PAGE = 'page';

    private const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly ExternalAppCredentials $credentials,
        private readonly ExternalApiClient $client,
    ) {}

    public function isConfigured(ExternalService $service): bool
    {
        return $this->credentials->isConfigured($service->provider());
    }

    /**
     * The provider's consent page for the service. Google is asked for offline access and to show
     * the consent screen, otherwise it only hands out a refresh token the very first time.
     *
     * @throws ExternalAccountException
     */
    public function buildConnectUrl(User $user, ExternalService $service, ?string $return_path = null, string $display = self::DISPLAY_TAB): string
    {
        $provider = $service->provider();
        $setting = $this->credentials->require($provider);
        $state = $this->issueState($user, $service, $return_path, $display);
        $scopes = implode(' ', [...$provider->signInScopes(), ...$service->scopes()]);

        $query = match ($provider) {
            ExternalProvider::Google => [
                'client_id' => $setting->client_id,
                'redirect_uri' => $this->credentials->redirectUri($provider),
                'response_type' => 'code',
                'scope' => $scopes,
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
                'include_granted_scopes' => 'true',
                'state' => $state,
            ],
            ExternalProvider::Microsoft => [
                'client_id' => $setting->client_id,
                'redirect_uri' => $this->credentials->redirectUri($provider),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope' => $scopes,
                'prompt' => 'select_account',
                'state' => $state,
            ],
        };

        return $provider->authorizeUrl($setting->tenant_id).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Reads and forgets the state of a connection, so it can only be used once.
     *
     * @return array{user: User, service: ExternalService, return_path: string|null, display: string}
     *
     * @throws ExternalAccountException
     */
    public function consumeState(?string $state): array
    {
        $payload = $state ? Cache::pull($this->stateCacheKey($state)) : null;
        $user = is_array($payload) && isset($payload['user_id']) ? User::query()->whereKey((int) $payload['user_id'])->first() : null;
        $service = is_array($payload) ? ExternalService::tryFrom((string) ($payload['service'] ?? '')) : null;

        if (! $user || ! $user->is_active || ! $service) {
            throw new ExternalAccountException(ExternalAccountException::CODE_INVALID_STATE, 'The connection request expired. Please try again.');
        }

        return [
            'user' => $user,
            'service' => $service,
            'return_path' => $payload['return_path'] ?? null,
            'display' => $payload['display'] ?? self::DISPLAY_PAGE,
        ];
    }

    /**
     * Stores the account the member just authorized. Connecting the same account again refreshes
     * its tokens and keeps the scopes granted before, so Gmail keeps working after Calendar is added.
     *
     * @throws ExternalAccountException
     */
    public function completeConnection(string $code, User $user, ExternalService $service): ExternalAccount
    {
        $provider = $service->provider();
        $tokens = $this->client->exchangeCode($provider, $code);
        $profile = $this->client->fetchProfile($provider, (string) $tokens['access_token']);

        if ($profile['id'] === '') {
            throw new ExternalAccountException(ExternalAccountException::CODE_PROVIDER_ERROR, "Your {$provider->label()} profile could not be read.");
        }

        $account = ExternalAccount::query()->firstOrNew([
            'user_id' => $user->id,
            'provider' => $provider->value,
            'provider_user_id' => $profile['id'],
        ]);

        $granted = preg_split('/[\s,]+/', (string) ($tokens['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $account->fill([
            'email' => $profile['email'],
            'name' => $profile['name'],
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'scopes' => array_values(array_unique([...($account->scopes ?? []), ...$granted])),
            'last_error' => null,
            'connected_at' => now(),
        ])->save();

        if (! $account->supports($service)) {
            throw new ExternalAccountException(ExternalAccountException::CODE_MISSING_SCOPES, "{$service->label()} needs every permission on the consent screen. Connect again and leave all of them checked.");
        }

        return $account;
    }

    /**
     * The member's accounts, the newest first, only those that can be used for `$service` when given.
     *
     * @return EloquentCollection<int, ExternalAccount>
     */
    public function accountsFor(User $user, ?ExternalService $service = null): EloquentCollection
    {
        $accounts = ExternalAccount::query()
            ->where('user_id', $user->id)
            ->when($service, fn ($query) => $query->where('provider', $service->provider()->value))
            ->latest('connected_at')
            ->latest('id')
            ->get();

        return $service ? $accounts->filter(fn (ExternalAccount $account) => $account->supports($service))->values() : $accounts;
    }

    /**
     * Forgets the account, revoking it at Google. Automations that read or send through it are
     * paused by the email poller or report a failed run, they never fall back to another account.
     */
    public function disconnect(ExternalAccount $account): void
    {
        $this->client->revoke($account);
        $account->delete();
    }

    /**
     * How many automations use each account, by trigger or by action. The id lives inside JSON,
     * so candidates are narrowed with a text match and counted here.
     *
     * @param  array<int, int>  $account_ids
     * @return array<int, int>
     */
    public function automationCounts(array $account_ids): array
    {
        if ($account_ids === []) {
            return [];
        }

        $counts = [];
        BoardAutomation::query()
            ->where(fn ($query) => $query->where('trigger_config', 'like', '%external_account_id%')
                ->orWhere('actions', 'like', '%external_account_id%')
                ->orWhere('else_actions', 'like', '%external_account_id%'))
            ->select(['id', 'trigger_config', 'action_params', 'actions', 'else_actions'])
            ->chunkById(500, function ($automations) use ($account_ids, &$counts) {
                foreach ($automations as $automation) {
                    foreach ($automation->externalAccountIds() as $account_id) {
                        if (in_array($account_id, $account_ids, true)) {
                            $counts[$account_id] = ($counts[$account_id] ?? 0) + 1;
                        }
                    }
                }
            });

        return $counts;
    }

    private function issueState(User $user, ExternalService $service, ?string $return_path, string $display): string
    {
        $state = Str::random(40);

        Cache::put(
            $this->stateCacheKey($state),
            ['user_id' => $user->id, 'service' => $service->value, 'return_path' => $return_path, 'display' => $display],
            now()->addMinutes(self::STATE_TTL_MINUTES),
        );

        return $state;
    }

    private function stateCacheKey(string $state): string
    {
        return "external_accounts:oauth_state:{$state}";
    }
}
