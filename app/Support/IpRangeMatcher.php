<?php

namespace App\Support;

/**
 * Matches an IP address against a list of allowed ranges for Administration > Authentication
 * > IP address restriction. Each range may be a single address ("203.0.113.5") or CIDR
 * notation ("203.0.113.0/24"); IPv4 and IPv6 both supported via PHP's native `inet_pton`.
 */
class IpRangeMatcher
{
    /**
     * @param  array<int, string>  $ranges
     */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, trim($range))) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $ip, string $range): bool
    {
        if ($range === '') {
            return false;
        }

        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ip_binary = @inet_pton($ip);
        $subnet_binary = @inet_pton($subnet);

        if ($ip_binary === false || $subnet_binary === false || strlen($ip_binary) !== strlen($subnet_binary)) {
            return false;
        }

        $bytes_to_check = intdiv($bits, 8);
        $remaining_bits = $bits % 8;

        if ($bytes_to_check > 0 && substr($ip_binary, 0, $bytes_to_check) !== substr($subnet_binary, 0, $bytes_to_check)) {
            return false;
        }

        if ($remaining_bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remaining_bits)) & 0xFF);
        $ip_byte = substr($ip_binary, $bytes_to_check, 1);
        $subnet_byte = substr($subnet_binary, $bytes_to_check, 1);

        return ($ip_byte & $mask) === ($subnet_byte & $mask);
    }
}
