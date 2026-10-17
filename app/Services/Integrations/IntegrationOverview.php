<?php

namespace App\Services\Integrations;

use App\Enums\ExternalService;
use App\Models\ExternalAccount;
use App\Models\SlackConnection;
use App\Models\User;
use App\Services\ExternalAccounts\ExternalAccountService;
use App\Services\Slack\SlackService;
use App\Services\Slack\SlackStatus;
use App\Support\AccountPermissions;

/**
 * Every app of the board's Integrations dialog and where the caller stands with
 * each one, so the sidebar and the overview can show it with a single request: Email is built in,
 * Slack, Gmail, Outlook and Google Calendar need the caller's own account.
 *
 * A status is `ready` (nothing to connect), `connected` (the caller has an account), `available`
 * (the app is set up, the caller can connect one) or `needs_setup` (an administrator must set up
 * the app in Administration first). Never includes a token.
 */
class IntegrationOverview
{
    public const STATUS_READY = 'ready';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_NEEDS_SETUP = 'needs_setup';

    public function __construct(
        private readonly SlackService $slack_service,
        private readonly ExternalAccountService $external_accounts,
    ) {}

    /**
     * @return array{can_manage: bool, data: array<int, array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $can_use_integrations = AccountPermissions::allows($user, AccountPermissions::USE_INTEGRATIONS);

        return [
            'can_manage' => $user->hasRole(SlackStatus::MANAGER_ROLES),
            'data' => [
                $this->email(),
                $this->slack($user, $can_use_integrations),
                ...array_map(fn (ExternalService $service) => $this->external($user, $service, $can_use_integrations), [ExternalService::Gmail, ExternalService::Outlook, ExternalService::GoogleCalendar]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function email(): array
    {
        return $this->present('email', 'Email', 'communication', self::STATUS_READY, true, false, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function slack(User $user, bool $can_use_integrations): array
    {
        $is_configured = $this->slack_service->isConfigured();
        $accounts = $this->slack_service->connectionsFor($user)->map(fn (SlackConnection $connection) => [
            'id' => $connection->id,
            'label' => $connection->slack_user_name ? "{$connection->slack_user_name} ({$connection->installation?->team_name})" : (string) $connection->installation?->team_name,
        ])->values()->all();

        return $this->present('slack', 'Slack', 'communication', $this->statusOf($is_configured, $accounts), $is_configured, $is_configured && $can_use_integrations, $accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function external(User $user, ExternalService $service, bool $can_use_integrations): array
    {
        $is_configured = $this->external_accounts->isConfigured($service);
        $accounts = $this->external_accounts->accountsFor($user, $service)->map(fn (ExternalAccount $account) => [
            'id' => $account->id,
            'label' => $account->email ?? $account->name ?? $service->label(),
        ])->values()->all();
        $category = $service === ExternalService::GoogleCalendar ? 'calendar' : 'email';

        return $this->present($service->value, $service->label(), $category, $this->statusOf($is_configured, $accounts), $is_configured, $is_configured && $can_use_integrations, $accounts);
    }

    /**
     * @param  array<int, array{id: int, label: string}>  $accounts
     */
    private function statusOf(bool $is_configured, array $accounts): string
    {
        if ($accounts !== []) {
            return self::STATUS_CONNECTED;
        }

        return $is_configured ? self::STATUS_AVAILABLE : self::STATUS_NEEDS_SETUP;
    }

    /**
     * @param  array<int, array{id: int, label: string}>  $accounts
     * @return array<string, mixed>
     */
    private function present(string $key, string $label, string $category, string $status, bool $is_configured, bool $can_connect, array $accounts): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'status' => $status,
            'is_configured' => $is_configured,
            'can_connect' => $can_connect,
            'accounts_count' => count($accounts),
            'accounts' => $accounts,
        ];
    }
}
