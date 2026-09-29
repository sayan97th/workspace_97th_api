<?php

namespace App\Support;

/**
 * Decides whether an automation may send a webhook to a URL. Only `http`/`https` URLs to a public
 * host are allowed, so an automation can never be used to reach the server itself or the private
 * network it runs in. Local development may opt in to private hosts with
 * `AUTOMATION_WEBHOOKS_ALLOW_PRIVATE=true`.
 */
final class OutboundWebhookUrl
{
    /**
     * Why `$url` cannot be used, or null when it can. `$resolve` also looks the host up, which the
     * sending job does right before each request.
     */
    public static function problem(string $url, bool $resolve = false): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Use a full http or https URL.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL may not contain a user name or password.';
        }
        if (self::allowsPrivateHosts()) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'Webhooks can only be sent to public addresses.';
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($resolve ? self::resolve($host) : []);
        if ($resolve && $addresses === []) {
            return 'The webhook host could not be found.';
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Webhooks can only be sent to public addresses.';
            }
        }

        return null;
    }

    private static function allowsPrivateHosts(): bool
    {
        return (bool) config('services.automation_webhooks.allow_private_hosts', false);
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
