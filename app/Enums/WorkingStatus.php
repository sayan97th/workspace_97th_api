<?php

namespace App\Enums;

enum WorkingStatus: string
{
    case Office = 'office';
    case Wfh = 'wfh';
    case Sick = 'sick';
    case Break = 'break';
    case Ooo = 'ooo';
    case Outside = 'outside';
    case Family = 'family';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Office => 'In the office',
            self::Wfh => 'Working from home',
            self::Sick => 'Out sick',
            self::Break => 'On break',
            self::Ooo => 'Out of office',
            self::Outside => 'Working outside',
            self::Family => 'Family time',
        };
    }

    /**
     * Get all status keys.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
