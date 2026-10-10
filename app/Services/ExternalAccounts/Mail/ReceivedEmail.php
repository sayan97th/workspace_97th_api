<?php

namespace App\Services\ExternalAccounts\Mail;

use Carbon\CarbonImmutable;

/**
 * One email read from a connected inbox, the same shape for Gmail and Outlook.
 */
final class ReceivedEmail
{
    /** Longest body handed to an automation, an update holds the rest of the item's story. */
    public const MAX_BODY_LENGTH = 10000;

    public function __construct(
        public readonly string $id,
        public readonly string $subject,
        public readonly ?string $from_email,
        public readonly ?string $from_name,
        public readonly string $body,
        public readonly CarbonImmutable $received_at,
    ) {}

    /**
     * What the automation reads through `{payload.*}` tokens, see `BoardAutomationMessageRenderer`.
     *
     * @return array<string, string>
     */
    public function toPayload(string $provider_label): array
    {
        return [
            'subject' => $this->subject !== '' ? $this->subject : '(no subject)',
            'from_email' => (string) $this->from_email,
            'from_name' => (string) ($this->from_name ?: $this->from_email),
            'body' => mb_substr($this->body, 0, self::MAX_BODY_LENGTH),
            'received_at' => $this->received_at->toIso8601String(),
            'provider' => $provider_label,
        ];
    }

    /**
     * Whether the email passes an automation's optional sender and subject filters, matched
     * without case anywhere in the address, the sender name or the subject.
     */
    public function matches(?string $from_filter, ?string $subject_filter): bool
    {
        $from_filter = mb_strtolower(trim((string) $from_filter));
        $subject_filter = mb_strtolower(trim((string) $subject_filter));
        $from = mb_strtolower("{$this->from_name} {$this->from_email}");

        return ($from_filter === '' || str_contains($from, $from_filter))
            && ($subject_filter === '' || str_contains(mb_strtolower($this->subject), $subject_filter));
    }

    /**
     * Plain text from an HTML body: line breaks kept, tags and entities removed.
     */
    public static function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
