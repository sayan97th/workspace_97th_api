<?php

namespace App\Support;

/**
 * Lightweight, dependency-free User-Agent parser for Session history's "Device" column
 * (e.g. "Mac · Chrome"). Deliberately not exhaustive — only detects the handful of
 * OS/browser combinations the UI needs to label, unlike a full UA-parsing library.
 */
class UserAgentParser
{
    public static function parse(?string $user_agent): string
    {
        if (! $user_agent) {
            return 'Unknown device';
        }

        $os = self::detectOs($user_agent);
        $browser = self::detectBrowser($user_agent);

        if (! $os && ! $browser) {
            return 'Unknown device';
        }

        return trim(implode(' · ', array_filter([$os, $browser])));
    }

    private static function detectOs(string $user_agent): ?string
    {
        return match (true) {
            (bool) preg_match('/iphone|ipad|ipod/i', $user_agent) => 'iOS',
            (bool) preg_match('/android/i', $user_agent) => 'Android',
            (bool) preg_match('/mac os x|macintosh/i', $user_agent) => 'Mac',
            (bool) preg_match('/windows/i', $user_agent) => 'Windows',
            (bool) preg_match('/linux/i', $user_agent) => 'Generic Linux',
            default => null,
        };
    }

    private static function detectBrowser(string $user_agent): ?string
    {
        return match (true) {
            (bool) preg_match('/edg\//i', $user_agent) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $user_agent) => 'Opera',
            (bool) preg_match('/firefox\//i', $user_agent) => 'Firefox',
            (bool) preg_match('/chrome\//i', $user_agent) && ! preg_match('/edg\/|opr\//i', $user_agent) => 'Chrome',
            (bool) preg_match('/safari\//i', $user_agent) && ! preg_match('/chrome\/|chromium\//i', $user_agent) => 'Safari',
            default => null,
        };
    }
}
