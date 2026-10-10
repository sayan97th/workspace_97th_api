<?php

namespace App\Services\ExternalAccounts\Mail;

use App\Models\ExternalAccount;
use App\Services\ExternalAccounts\ExternalApiClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;

/**
 * Gmail through the Gmail REST API: `messages.list` with an `after:` search, `messages.get` for
 * each one, and `messages.send` with a raw RFC 2822 message.
 */
class GmailMailbox implements Mailbox
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private readonly ExternalApiClient $client) {}

    public function receivedSince(ExternalAccount $account, CarbonInterface $since, int $limit): array
    {
        $ids = $this->client->call($account, fn (PendingRequest $http) => $http->get(self::API.'/messages', [
            // `after:` is in whole seconds, the receipts table drops what was already imported.
            'q' => 'in:inbox after:'.$since->getTimestamp(),
            'maxResults' => $limit,
        ]), 'Gmail could not list the inbox.')->json('messages') ?? [];

        $emails = [];
        foreach ($ids as $entry) {
            $message = $this->client->call(
                $account,
                fn (PendingRequest $http) => $http->get(self::API.'/messages/'.urlencode((string) $entry['id']), ['format' => 'full']),
                'Gmail could not read an email.',
            )->json();
            $emails[] = $this->toEmail((array) $message);
        }

        usort($emails, fn (ReceivedEmail $a, ReceivedEmail $b) => $a->received_at <=> $b->received_at);

        return array_values(array_filter($emails, fn (ReceivedEmail $email) => $email->received_at->greaterThan($since)));
    }

    public function send(ExternalAccount $account, array $to, string $subject, string $html_body): void
    {
        $raw = implode("\r\n", [
            'To: '.implode(', ', $to),
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html_body)),
        ]);

        $this->client->call(
            $account,
            fn (PendingRequest $http) => $http->post(self::API.'/messages/send', ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]),
            'Gmail could not send the email.',
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function toEmail(array $message): ReceivedEmail
    {
        $headers = [];
        foreach ((array) data_get($message, 'payload.headers', []) as $header) {
            $headers[mb_strtolower((string) ($header['name'] ?? ''))] = (string) ($header['value'] ?? '');
        }
        [$from_name, $from_email] = self::parseAddress($headers['from'] ?? '');

        $plain = $this->findBody((array) ($message['payload'] ?? []), 'text/plain');
        $body = $plain ?? ReceivedEmail::textFromHtml((string) $this->findBody((array) ($message['payload'] ?? []), 'text/html'));

        return new ReceivedEmail(
            id: (string) $message['id'],
            subject: trim($headers['subject'] ?? ''),
            from_email: $from_email,
            from_name: $from_name,
            body: trim($body !== '' ? $body : (string) ($message['snippet'] ?? '')),
            received_at: CarbonImmutable::createFromTimestampMs((int) ($message['internalDate'] ?? 0)),
        );
    }

    /**
     * The first part of `$mime_type` anywhere in the message tree, decoded.
     *
     * @param  array<string, mixed>  $part
     */
    private function findBody(array $part, string $mime_type): ?string
    {
        if (($part['mimeType'] ?? null) === $mime_type && isset($part['body']['data'])) {
            return (string) base64_decode(strtr((string) $part['body']['data'], '-_', '+/'));
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            $found = $this->findBody((array) $child, $mime_type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * `"Ada Lovelace" <ada@example.com>` into its name and address.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parseAddress(string $value): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $value, $matches) === 1) {
            return [trim($matches[1]) !== '' ? trim($matches[1]) : null, trim($matches[2])];
        }

        $value = trim($value);

        return [null, $value !== '' ? $value : null];
    }
}
