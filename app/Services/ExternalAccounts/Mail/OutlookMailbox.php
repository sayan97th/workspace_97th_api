<?php

namespace App\Services\ExternalAccounts\Mail;

use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalApiClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;

/**
 * Outlook through Microsoft Graph: the inbox folder's messages filtered by `receivedDateTime`,
 * bodies asked for as plain text, and `sendMail` for sending.
 */
class OutlookMailbox implements Mailbox
{
    private const API = 'https://graph.microsoft.com/v1.0/me';

    public function __construct(private readonly ExternalApiClient $client) {}

    public function receivedSince(ExternalAccount $account, CarbonInterface $since, int $limit): array
    {
        $messages = $this->client->call($account, fn (PendingRequest $http) => $http
            ->withHeaders(['Prefer' => 'outlook.body-content-type="text"'])
            ->get(self::API.'/mailFolders/inbox/messages', [
                '$filter' => 'receivedDateTime gt '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                '$orderby' => 'receivedDateTime asc',
                '$top' => $limit,
                '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview',
            ]), 'Outlook could not list the inbox.')->json('value') ?? [];

        return array_map(fn (array $message) => new ReceivedEmail(
            id: (string) $message['id'],
            subject: trim((string) ($message['subject'] ?? '')),
            from_email: data_get($message, 'from.emailAddress.address'),
            from_name: data_get($message, 'from.emailAddress.name'),
            body: trim((string) (data_get($message, 'body.content') ?: ($message['bodyPreview'] ?? ''))),
            received_at: CarbonImmutable::parse((string) $message['receivedDateTime']),
        ), array_values(array_filter((array) $messages, 'is_array')));
    }

    public function send(ExternalAccount $account, array $to, string $subject, string $html_body): void
    {
        $this->client->call($account, fn (PendingRequest $http) => $http->post(self::API.'/sendMail', [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $html_body],
                'toRecipients' => array_map(fn (string $address) => ['emailAddress' => ['address' => $address]], array_values($to)),
            ],
            'saveToSentItems' => true,
        ]), 'Outlook could not send the email.');
    }
}
