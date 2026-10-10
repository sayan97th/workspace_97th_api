<?php

namespace App\Services\ExternalAccounts\Mail;

use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalAccountException;
use Carbon\CarbonInterface;

/**
 * A connected inbox: the newest emails since a moment, and sending one as the account.
 */
interface Mailbox
{
    /**
     * Inbox emails received after `$since`, the oldest first, at most `$limit`.
     *
     * @return array<int, ReceivedEmail>
     *
     * @throws ExternalAccountException
     */
    public function receivedSince(ExternalAccount $account, CarbonInterface $since, int $limit): array;

    /**
     * Sends an HTML email from the account.
     *
     * @param  array<int, string>  $to
     *
     * @throws ExternalAccountException
     */
    public function send(ExternalAccount $account, array $to, string $subject, string $html_body): void;
}
