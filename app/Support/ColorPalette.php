<?php

namespace App\Support;

/**
 * The colors the frontend's pickers offer (the original Status palette), mirrored
 * from `src/lib/color-palette.ts`; keep both in sync. Tonk's alternative palette,
 * and the maps between the two, live in {@see TonkPalette}.
 */
final class ColorPalette
{
    public const BLUE = '#2074d4';

    public const OCEAN = '#1071c4';

    public const SKY = '#3a8fc9';

    public const PLUM = '#7b3f98';

    public const VIOLET = '#7b52c9';

    public const GREEN = '#12c46b';

    public const FOREST = '#12724a';

    public const JADE = '#2f9e78';

    public const AMBER = '#f2a53c';

    public const ORANGE = '#f2662a';

    public const RED = '#e04455';

    public const PINK = '#f453b0';

    public const BROWN = '#8a6248';

    public const CHARCOAL = '#3a3a3a';

    public const LIGHT_BLUE = '#9dd6f2';

    public const SLATE = '#6b7189';

    public const MUSTARD = '#e2b203';

    public const SILVER = '#c9ccd4';

    /**
     * Every palette color, in the picker's order (6 per row).
     *
     * @var array<int, string>
     */
    public const COLORS = [
        self::BLUE, self::OCEAN, self::SKY, self::PLUM, self::VIOLET, self::GREEN,
        self::FOREST, self::JADE, self::AMBER, self::ORANGE, self::RED, self::PINK,
        self::BROWN, self::CHARCOAL, self::LIGHT_BLUE, self::SLATE, self::MUSTARD, self::SILVER,
    ];
}
