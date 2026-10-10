<?php

namespace App\Services\ExternalAccounts;

use App\Enums\ExternalProvider;
use App\Models\BoardAutomation;
use App\Models\BoardAutomationEmailReceipt;
use App\Models\ExternalAccount;
use App\Services\Board\BoardAutomationService;
use App\Services\ExternalAccounts\Mail\MailboxResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Checks the inbox of every enabled "When an email is received" automation and runs it once per
 * new email, like monday.com's Gmail and Outlook recipes. Each automation remembers when it last
 * looked (`state.email_checked_at`), the window overlaps a little so an email that lands while a
 * poll runs is never missed, and the receipts table makes sure it is never imported twice.
 */
class EmailTriggerPoller
{
    /** Emails read per automation and poll, the next poll picks up the rest. */
    public const BATCH_SIZE = 25;

    /** How far back each poll looks again, providers index new emails with a short delay. */
    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly MailboxResolver $mailboxes,
        private readonly BoardAutomationService $automation_service,
    ) {}

    /**
     * Returns how many emails were turned into automation runs.
     */
    public function poll(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());
        $ran_count = 0;

        $automations = BoardAutomation::query()
            ->where('is_enabled', true)
            ->where('trigger_type', BoardAutomation::TRIGGER_EMAIL_RECEIVED)
            ->with(['board', 'creator', 'owner'])
            ->get();

        foreach ($automations as $automation) {
            if ($this->automation_service->isBoardPaused($automation->board_id)) {
                continue;
            }

            $ran_count += $this->pollAutomation($automation, $now);
        }

        return $ran_count;
    }

    private function pollAutomation(BoardAutomation $automation, CarbonImmutable $now): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $account = ExternalAccount::query()->find((int) ($config['external_account_id'] ?? 0));

        if (! $account) {
            $this->automation_service->pause($automation, 'The email account this automation reads was disconnected. Edit it and choose an account again.');

            return 0;
        }

        $checked_at = isset($automation->state['email_checked_at']) ? CarbonImmutable::parse((string) $automation->state['email_checked_at']) : null;
        // A new automation only reacts to emails that arrive after it was created.
        $since = ($checked_at ?? CarbonImmutable::instance($automation->created_at ?? $now))->subMinutes(self::OVERLAP_MINUTES);

        try {
            $emails = $this->mailboxes->for($account)->receivedSince($account, $since, self::BATCH_SIZE);
        } catch (ExternalAccountException $exception) {
            if ($exception->isTokenRevoked()) {
                $this->automation_service->pause($automation, $exception->getMessage());
            } else {
                Log::warning('An email automation could not read its inbox', ['automation_id' => $automation->id, 'error' => $exception->error_code]);
            }

            return 0;
        }

        $ran_count = 0;
        $newest = $checked_at ?? $since;
        foreach ($emails as $email) {
            $newest = $email->received_at->greaterThan($newest) ? $email->received_at : $newest;

            if (! $email->matches($config['from_filter'] ?? null, $config['subject_filter'] ?? null) || ! $this->claim($automation, $email->id, $email->received_at)) {
                continue;
            }

            $this->automation_service->handleEmailReceived($automation, $email->toPayload($account->provider === ExternalProvider::Google ? 'Gmail' : 'Outlook'));
            $ran_count++;
        }

        // A full batch may have more behind it, the next poll continues from the newest email read.
        $next_checked_at = count($emails) >= self::BATCH_SIZE ? $newest : $now;
        $automation->forceFill(['state' => [...($automation->state ?? []), 'email_checked_at' => $next_checked_at->toIso8601String()]])->saveQuietly();
        $account->forceFill(['last_used_at' => $now, 'last_error' => null])->save();

        return $ran_count;
    }

    /**
     * Records the email for the automation, false when it was already imported.
     */
    private function claim(BoardAutomation $automation, string $message_id, CarbonImmutable $received_at): bool
    {
        try {
            BoardAutomationEmailReceipt::create(['board_automation_id' => $automation->id, 'message_id' => $message_id, 'received_at' => $received_at]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
