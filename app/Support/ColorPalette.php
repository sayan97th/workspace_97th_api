<?php

namespace App\Support;

/**
 * The colors the frontend's pickers offer (the original Status palette), mirrored
 * from `src/lib/color-palette.ts`; keep both in sync.
 *
 * Tonk's palette replaced it for a short while and was then rolled back by client
 * request, so {@see TONK_COLORS} pairs each of its colors with the original color
 * it replaced. `board:remap-palette-colors` uses that map to move anything saved
 * during that period back.
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

    /**
     * Tonk's palette color => the original color it stood in for, picked by
     * meaning (Done green, Stuck red, In Prod forest green) so a rollback keeps
     * what a team gave each label. Crimson and Deep teal go back to the old
     * Critical and Pending defaults, which were never in the picker.
     *
     * @var array<string, string>
     */
    private const TONK_COLORS = [
        '#e53e2e' => self::RED,
        '#800f12' => '#b02f43',
        '#ab350d' => self::BROWN,
        '#d55b08' => self::ORANGE,
        '#e29703' => self::AMBER,
        '#567460' => self::FOREST,
        '#60943f' => self::GREEN,
        '#8fac87' => self::JADE,
        '#8cd1cb' => self::LIGHT_BLUE,
        '#0499b1' => self::OCEAN,
        '#2c6776' => '#1f5b9e',
        '#2783c5' => self::BLUE,
        '#e697b2' => self::PINK,
        '#9a7ab6' => self::VIOLET,
        '#7f2b81' => self::PLUM,
        '#c9b893' => self::MUSTARD,
        '#7e8889' => self::SLATE,
        '#2b3c40' => self::CHARCOAL,
    ];

    /** The original color a Tonk color replaced, or null when the color is not one of Tonk's. */
    public static function fromTonk(?string $hex): ?string
    {
        return $hex === null ? null : self::TONK_COLORS[strtolower($hex)] ?? null;
    }
}
