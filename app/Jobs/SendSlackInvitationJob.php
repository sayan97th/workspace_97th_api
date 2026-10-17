<?php

namespace App\Jobs;

use App\Models\SlackInstallation;
use App\Models\SlackUserLink;
use App\Models\User;
use App\Services\Slack\SlackClient;
use App\Services\Slack\SlackException;
use App\Services\Slack\SlackNotifier;
use App\Services\Slack\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds who uses `$email` in a Slack workspace and hands the invitation message to
 * {@see SendSlackMessageJob}. Queued by {@see SlackNotifier::notifyInvitee()}, so inviting
 * someone never waits on Slack.
 *
 * The invitee does not need an account or to have signed in to Slack from the app: a member link
 * made earlier wins, otherwise the bot looks the email up (`users.lookupByEmail`, which needs
 * `users:read.email`). Nobody in Slack with that email means no message, the email still goes out.
 * When the email belongs to an active account with no link yet, the account is linked the same
 * way "Match members by email" does, so their later notifications reach Slack too.
 */
class SendSlackInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function __construct(
        public int $installation_id,
        public string $email,
        public string $text,
        public array $blocks = [],
    ) {}

    public function handle(SlackClient $client, SlackService $slack_service): void
    {
        $installation = SlackInstallation::find($this->installation_id);

        if (! $installation || ! $installation->notify_invitations) {
            return;
        }

        $slack_user_id = $this->linkedSlackUserId($installation);

        if ($slack_user_id === null) {
            try {
                $slack_user_id = $this->lookupSlackUserId($installation, $client);
            } catch (SlackException $exception) {
                if ($exception->isRateLimited()) {
                    $this->release($exception->retry_after ?? 30);

                    return;
                }

                if ($exception->isTokenInvalid()) {
                    $slack_service->handleRevokedInstallation($installation);
                }

                if ($exception->isTokenInvalid() || $exception->isPermanent()) {
                    Log::warning('Slack invitation not delivered', ['error' => $exception->error_code]);

                    return;
                }

                throw $exception;
            }
        }

        if ($slack_user_id === null) {
            return;
        }

        SendSlackMessageJob::dispatch($installation->id, $slack_user_id, $this->text, $this->blocks);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Slack invitation lookup failed', ['error' => $exception->getMessage()]);
    }

    /**
     * The Slack member id an account with this email already linked in `$installation`.
     */
    private function linkedSlackUserId(SlackInstallation $installation): ?string
    {
        return SlackUserLink::query()
            ->where('slack_installation_id', $installation->id)
            ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [mb_strtolower($this->email)]))
            ->value('slack_user_id');
    }

    /**
     * Asks Slack who uses the email. Null when nobody does, the member was deactivated, it is a
     * bot, or the app was installed without `users:read.email`.
     *
     * @throws SlackException
     */
    private function lookupSlackUserId(SlackInstallation $installation, SlackClient $client): ?string
    {
        if (! $installation->hasScope('users:read.email')) {
            return null;
        }

        try {
            $response = $client->lookupUserByEmail($installation->bot_token, $this->email);
        } catch (SlackException $exception) {
            if ($exception->error_code === 'users_not_found') {
                return null;
            }

            throw $exception;
        }

        $member = $response['user'] ?? [];
        $slack_user_id = is_string($member['id'] ?? null) ? $member['id'] : null;

        if ($slack_user_id === null || ($member['deleted'] ?? false) || ($member['is_bot'] ?? false)) {
            return null;
        }

        $this->linkAccount($installation, $slack_user_id, $member['real_name'] ?? $member['name'] ?? null);

        return $slack_user_id;
    }

    /**
     * Links the active account that uses the email, unless that Slack member is already linked
     * to someone else in this workspace, the same rule as "Match members by email".
     */
    private function linkAccount(SlackInstallation $installation, string $slack_user_id, mixed $slack_display_name): void
    {
        $user = User::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($this->email)])
            ->first();

        if (! $user || SlackUserLink::where('slack_installation_id', $installation->id)->where('slack_user_id', $slack_user_id)->exists()) {
            return;
        }

        SlackUserLink::firstOrCreate(
            ['user_id' => $user->id, 'slack_installation_id' => $installation->id],
            [
                'slack_user_id' => $slack_user_id,
                'slack_display_name' => is_string($slack_display_name) ? $slack_display_name : null,
                'linked_at' => now(),
            ],
        );
    }
}
