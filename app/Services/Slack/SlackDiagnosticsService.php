<?php

namespace App\Services\Slack;

use App\Models\SlackInstallation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Checks every piece the Slack integration depends on, one at a time, so an administrator
 * can see exactly which one is broken: the credentials in the environment, the redirect URL, the signing secret, the installed workspace, its bot
 * token and scopes, the channel list and the caller's own linked account.
 *
 * A check that depends on an earlier one which failed is reported as `skipped` rather than
 * failing again with a less useful error.
 */
class SlackDiagnosticsService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    private const SIGNED_REQUEST_CACHE_KEY = 'slack_diagnostics:last_signed_request';

    public function __construct(
        private readonly SlackClient $client,
        private readonly SlackService $slack_service,
    ) {}

    /**
     * @return array{
     *     ran_at: string,
     *     summary: array<string, int>,
     *     app: array<string, mixed>,
     *     checks: array<int, array{key: string, label: string, status: string, detail: string}>
     * }
     */
    public function run(User $user): array
    {
        $checks = [];
        $is_configured = $this->slack_service->isConfigured();

        $checks[] = $this->checkCredentials($is_configured);
        $checks[] = $this->checkRedirectUri();
        $checks[] = $this->checkSigningSecret();

        $installation = SlackInstallation::current();
        $checks[] = $this->checkWorkspace($installation, $is_configured);

        $bot_check = $installation
            ? $this->checkBotToken($installation)
            : $this->skipped('bot_token', 'Bot token is valid', 'Connect a workspace first.');
        $checks[] = $bot_check;

        $has_working_bot = $installation && $bot_check['status'] === self::STATUS_PASSED;

        $checks[] = $installation
            ? $this->checkBotScopes($installation)
            : $this->skipped('bot_scopes', 'Bot has every permission it needs', 'Connect a workspace first.');
        $checks[] = $has_working_bot
            ? $this->checkChannels($installation)
            : $this->skipped('channels', 'Bot can list channels', 'Needs a valid bot token.');
        $checks[] = $installation
            ? $this->checkPersonalLink($installation, $user)
            : $this->skipped('personal_link', 'Your Slack account is linked', 'Connect a workspace first.');

        return [
            'ran_at' => now()->toIso8601String(),
            'summary' => $this->summarize($checks),
            'app' => [
                'client_id' => config('services.slack.client_id') ?: null,
                'redirect_uri' => (string) config('services.slack.redirect'),
                'events_url' => $this->eventsUrl(),
                'bot_scopes' => SlackService::BOT_SCOPES,
                'user_scopes' => SlackService::USER_SCOPES,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * Posts a test message to a channel right away, not through the queue, so a failure
     * comes back with Slack's real error instead of disappearing into a failed job.
     *
     * @throws SlackException
     */
    public function sendChannelTest(string $channel_id, User $actor): void
    {
        $installation = SlackInstallation::current()
            ?? throw new SlackException('not_installed', 'Slack is not connected to this account yet.');

        $text = "Slack test message sent by {$actor->full_name}. The integration can post to this channel.";

        $this->client->postMessage($installation->bot_token, $channel_id, $text);
    }

    /**
     * Remembers that a request carrying a valid Slack signature arrived, which is the only
     * proof that the signing secret matches the one Slack holds.
     */
    public function recordSignedRequest(string $type): void
    {
        Cache::forever(self::SIGNED_REQUEST_CACHE_KEY, [
            'type' => $type,
            'received_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkCredentials(bool $is_configured): array
    {
        $label = 'Client ID and secret are set';

        if (! $is_configured) {
            return $this->result('credentials', $label, self::STATUS_FAILED, 'Set SLACK_CLIENT_ID and SLACK_CLIENT_SECRET in the API environment, then run php artisan config:clear.');
        }

        if (! preg_match('/^\d+\.\d+$/', (string) config('services.slack.client_id'))) {
            return $this->result('credentials', $label, self::STATUS_WARNING, 'SLACK_CLIENT_ID does not look like a Slack client ID, which is two numbers joined by a dot.');
        }

        // Slack only checks the secret while exchanging a real authorization code, so there is
        // no way to test it from here. A successful "Add to Slack" is what proves it.
        return $this->result('credentials', $label, self::STATUS_PASSED, 'Both values are present. Slack verifies the secret during "Add to Slack", a wrong one makes that step fail with bad_client_secret.');
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkRedirectUri(): array
    {
        $label = 'Redirect URL is usable';
        $redirect_uri = (string) config('services.slack.redirect');

        if (! filter_var($redirect_uri, FILTER_VALIDATE_URL)) {
            return $this->result('redirect_uri', $label, self::STATUS_FAILED, 'The redirect URL is not a valid URL. Check APP_URL or SLACK_REDIRECT_URI.');
        }

        if (parse_url($redirect_uri, PHP_URL_SCHEME) !== 'https') {
            return $this->result('redirect_uri', $label, self::STATUS_WARNING, 'Slack only accepts HTTPS redirect URLs. For local testing expose the API through an HTTPS tunnel and set SLACK_REDIRECT_URI to it.');
        }

        return $this->result('redirect_uri', $label, self::STATUS_PASSED, 'Make sure this exact URL is listed under OAuth & Permissions in the Slack app.');
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkSigningSecret(): array
    {
        $label = 'Signing secret is verified';
        $signing_secret = (string) config('services.slack.signing_secret');

        if ($signing_secret === '') {
            return $this->result('signing_secret', $label, self::STATUS_FAILED, 'Set SLACK_SIGNING_SECRET in the API environment.');
        }

        if (! preg_match('/^[a-f0-9]{32}$/', $signing_secret)) {
            return $this->result('signing_secret', $label, self::STATUS_WARNING, 'SLACK_SIGNING_SECRET does not look like a Slack signing secret, which is 32 hexadecimal characters.');
        }

        $last_request = Cache::get(self::SIGNED_REQUEST_CACHE_KEY);

        if (! is_array($last_request)) {
            return $this->result('signing_secret', $label, self::STATUS_WARNING, 'Set, but Slack has not sent a signed request yet. Enter the events URL below as the Request URL under Event Subscriptions, Slack then sends a signed challenge that proves the secret.');
        }

        $received_at = Carbon::parse($last_request['received_at'])->toDayDateTimeString();

        return $this->result('signing_secret', $label, self::STATUS_PASSED, "Last signed request from Slack ({$last_request['type']}) was verified on {$received_at}.");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkWorkspace(?SlackInstallation $installation, bool $is_configured): array
    {
        $label = 'A Slack workspace is connected';

        if (! $installation) {
            return $this->result('workspace', $label, self::STATUS_FAILED, $is_configured
                ? 'Use "Add to Slack" to install the app into your workspace.'
                : 'Add the credentials, then use "Add to Slack".');
        }

        return $this->result('workspace', $label, self::STATUS_PASSED, "Connected to {$installation->team_name} ({$installation->team_id}).");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkBotToken(SlackInstallation $installation): array
    {
        $label = 'Bot token is valid';

        try {
            $identity = $this->client->authTest($installation->bot_token);
        } catch (SlackException $exception) {
            return $this->result('bot_token', $label, self::STATUS_FAILED, $exception->isTokenInvalid()
                ? 'Slack rejected the bot token, the app was probably removed from the workspace. Disconnect and add it to Slack again.'
                : "Slack returned an error ({$exception->error_code}).");
        }

        if (($identity['team_id'] ?? null) !== $installation->team_id) {
            return $this->result('bot_token', $label, self::STATUS_FAILED, 'The bot token belongs to a different workspace than the one stored. Disconnect and add it to Slack again.');
        }

        $bot_name = $identity['user'] ?? 'the bot';

        return $this->result('bot_token', $label, self::STATUS_PASSED, "Authenticated as {$bot_name} in {$identity['team']}.");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkBotScopes(SlackInstallation $installation): array
    {
        $label = 'Bot has every permission it needs';
        $granted_scopes = array_filter(array_map('trim', explode(',', (string) $installation->scopes)));
        $missing_scopes = array_values(array_diff(SlackService::BOT_SCOPES, $granted_scopes));

        if ($missing_scopes !== []) {
            return $this->result('bot_scopes', $label, self::STATUS_FAILED, 'Missing '.implode(', ', $missing_scopes).'. Add them under OAuth & Permissions, then use "Add to Slack" again.');
        }

        return $this->result('bot_scopes', $label, self::STATUS_PASSED, 'Granted '.implode(', ', SlackService::BOT_SCOPES).'.');
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkChannels(SlackInstallation $installation): array
    {
        $label = 'Bot can list channels';

        try {
            $channel_count = count($this->slack_service->listChannels($installation));
        } catch (SlackException $exception) {
            return $this->result('channels', $label, self::STATUS_FAILED, "Slack returned an error ({$exception->error_code}).");
        }

        if ($channel_count === 0) {
            return $this->result('channels', $label, self::STATUS_WARNING, 'The bot sees no channels. Private channels only appear after the app is invited to them.');
        }

        return $this->result('channels', $label, self::STATUS_PASSED, "The bot sees {$channel_count} ".str('channel')->plural($channel_count).'.');
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function checkPersonalLink(SlackInstallation $installation, User $user): array
    {
        $label = 'Your Slack account is linked';
        $link = $user->slackLink()->where('slack_installation_id', $installation->id)->first();

        if (! $link) {
            return $this->result('personal_link', $label, self::STATUS_WARNING, 'Connect your own Slack account to receive direct messages and try the direct message test.');
        }

        $display_name = $link->slack_display_name ?? $link->slack_user_id;

        return $this->result('personal_link', $label, self::STATUS_PASSED, "Linked to {$display_name}.");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function skipped(string $key, string $label, string $detail): array
    {
        return $this->result($key, $label, self::STATUS_SKIPPED, $detail);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function result(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param  array<int, array{status: string}>  $checks
     * @return array<string, int>
     */
    private function summarize(array $checks): array
    {
        $summary = array_fill_keys([self::STATUS_PASSED, self::STATUS_WARNING, self::STATUS_FAILED, self::STATUS_SKIPPED], 0);

        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return $summary;
    }

    private function eventsUrl(): string
    {
        $redirect_uri = (string) config('services.slack.redirect');

        // The events route sits next to the callback, so it follows SLACK_REDIRECT_URI when a
        // tunnel is used instead of pointing at the local APP_URL.
        return str_ends_with($redirect_uri, '/callback')
            ? substr($redirect_uri, 0, -strlen('/callback')).'/events'
            : rtrim((string) config('app.url'), '/').'/api/integrations/slack/events';
    }
}
