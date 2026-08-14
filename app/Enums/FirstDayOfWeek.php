<?php

namespace App\Enums;

enum FirstDayOfWeek: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';

    public function label(): string
    {
        return match ($this) {
            self::Sunday => 'Sunday',
            self::Monday => 'Monday',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $day) => $day->value, self::cases());
    }
}
