<?php

namespace App\Enums;

enum TimeFormat: string
{
    case Twelve = '12';
    case TwentyFour = '24';

    public function label(): string
    {
        return match ($this) {
            self::Twelve => '12 Hours',
            self::TwentyFour => '24 Hours',
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
