<?php

namespace App\Services\ExternalAccounts\Mail;

use App\Enums\ExternalProvider;
use App\Models\ExternalAccount;

/**
 * The inbox implementation of an account's provider.
 */
class MailboxResolver
{
    public function __construct(
        private readonly GmailMailbox $gmail,
        private readonly OutlookMailbox $outlook,
    ) {}

    public function for(ExternalAccount $account): Mailbox
    {
        return match ($account->provider) {
            ExternalProvider::Google => $this->gmail,
            ExternalProvider::Microsoft => $this->outlook,
        };
    }
}
