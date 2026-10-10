<?php

namespace App\Jobs;

use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalAccountException;
use App\Services\ExternalAccounts\Mail\MailboxResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Sends an automation email from a member's own Gmail or Outlook account, so the recipient sees
 * it come from that person like monday.com's Gmail and Outlook "send an email" recipes. Carries
 * the account id, the encrypted tokens never go into the queue payload.
 */
class SendConnectedAccountEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<int, string>  $to
     */
    public function __construct(
        public int $external_account_id,
        public array $to,
        public string $subject,
        public string $html_body,
    ) {}

    public function handle(MailboxResolver $mailboxes): void
    {
        $account = ExternalAccount::query()->find($this->external_account_id);
        if (! $account) {
            return;
        }

        try {
            $mailboxes->for($account)->send($account, $this->to, $this->subject, $this->html_body);
            $account->forceFill(['last_used_at' => now()])->save();
        } catch (ExternalAccountException $exception) {
            Log::warning('An automation email could not be sent from a connected account', ['account_id' => $account->id, 'error' => $exception->error_code]);

            if ($exception->isTokenRevoked() || $exception->error_code === ExternalAccountException::CODE_MISSING_SCOPES) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
