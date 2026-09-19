<?php

namespace App\Enums;

/**
 * How often a user receives the unread-notifications summary email sent by
 * `notifications:send-digests`.
 */
enum EmailDigestFrequency: string
{
    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * How far back the digest looks for unread notifications.
     */
    public function windowInHours(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Daily => 24,
            self::Weekly => 24 * 7,
        };
    }
}
