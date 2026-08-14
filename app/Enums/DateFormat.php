<?php

namespace App\Enums;

enum DateFormat: string
{
    case Long = 'long';
    case Euro = 'euro';

    public function label(): string
    {
        return match ($this) {
            self::Long => 'July 12, 2026',
            self::Euro => '12 July, 2026',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $format) => $format->value, self::cases());
    }
}
