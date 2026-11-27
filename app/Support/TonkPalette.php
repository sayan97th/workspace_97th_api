<?php

namespace App\Support;

/**
 * Tonk's 18 color palette for 97th Floor, kept outside the pickers (they offer
 * {@see ColorPalette}). It maps colors both ways, by meaning (Done green, Stuck
 * red, In Prod moss) rather than by nearest hex:
 *
 * - {@see fromColor()}: an original palette color, an old default or a monday.com
 *   color => its Tonk counterpart, used by `board:apply-tonk-colors`.
 * - {@see toOriginal()}: a Tonk color => the original color it stands for, used
 *   by `board:remap-palette-colors` to roll back.
 *
 * The two maps are inverses for every original palette color with a Tonk pair,
 * so applying and then rolling back returns the same colors. Sky and Silver have
 * no pair of their own (they become Blue and Slate), and colors outside both maps
 * (custom picks) are never touched.
 */
final class TonkPalette
{
    public const RED = '#e53e2e';

    public const CRIMSON = '#800f12';

    public const RUST = '#ab350d';

    public const ORANGE = '#d55b08';

    public const AMBER = '#e29703';

    public const MOSS = '#567460';

    public const GREEN = '#60943f';

    public const SAGE = '#8fac87';

    public const AQUA = '#8cd1cb';

    public const TEAL = '#0499b1';

    public const DEEP_TEAL = '#2c6776';

    public const BLUE = '#2783c5';

    public const ROSE = '#e697b2';

    public const LAVENDER = '#9a7ab6';

    public const PLUM = '#7f2b81';

    public const SAND = '#c9b893';

    public const SLATE = '#7e8889';

    public const CHARCOAL = '#2b3c40';

    /**
     * Tonk's palette in the design's order (6 per row).
     *
     * @var array<int, string>
     */
    public const COLORS = [
        self::RED, self::CRIMSON, self::RUST, self::ORANGE, self::AMBER, self::MOSS,
        self::GREEN, self::SAGE, self::AQUA, self::TEAL, self::DEEP_TEAL, self::BLUE,
        self::ROSE, self::LAVENDER, self::PLUM, self::SAND, self::SLATE, self::CHARCOAL,
    ];

    /**
     * Original palette colors, old board defaults and monday.com colors => Tonk.
     *
     * @var array<string, string>
     */
    private const TO_TONK = [
        // The original picker palette (ColorPalette), each the inverse of TO_ORIGINAL.
        ColorPalette::BLUE => self::BLUE,
        ColorPalette::OCEAN => self::TEAL,
        ColorPalette::SKY => self::BLUE,
        ColorPalette::PLUM => self::PLUM,
        ColorPalette::VIOLET => self::LAVENDER,
        ColorPalette::GREEN => self::GREEN,
        ColorPalette::FOREST => self::MOSS,
        ColorPalette::JADE => self::SAGE,
        ColorPalette::AMBER => self::AMBER,
        ColorPalette::ORANGE => self::ORANGE,
        ColorPalette::RED => self::RED,
        ColorPalette::PINK => self::ROSE,
        ColorPalette::BROWN => self::RUST,
        ColorPalette::CHARCOAL => self::CHARCOAL,
        ColorPalette::LIGHT_BLUE => self::AQUA,
        ColorPalette::SLATE => self::SLATE,
        ColorPalette::MUSTARD => self::SAND,
        ColorPalette::SILVER => self::SLATE,
        // Old defaults outside the picker: Pending, and the Label column's Low/Medium/High/Critical.
        '#1f5b9e' => self::DEEP_TEAL,
        '#5b6180' => self::SLATE,
        '#8a6d1f' => self::AMBER,
        '#5b3fbd' => self::PLUM,
        '#b02f43' => self::CRIMSON,
        // monday.com colors (API defaults, imports, Priority preset, older palettes).
        '#00c875' => self::GREEN,
        '#9cd326' => self::GREEN,
        '#3faf5a' => self::GREEN,
        '#037f4c' => self::MOSS,
        '#fdab3d' => self::AMBER,
        '#ffcb00' => self::AMBER,
        '#e2a200' => self::AMBER,
        '#cab641' => self::AMBER,
        '#e8b23a' => self::AMBER,
        '#c9a227' => self::SAND,
        '#e2445c' => self::RED,
        '#ff7575' => self::RED,
        '#bb3354' => self::CRIMSON,
        '#b03060' => self::CRIMSON,
        '#ff642e' => self::ORANGE,
        '#d4763a' => self::ORANGE,
        '#e0723f' => self::ORANGE,
        '#7f5347' => self::RUST,
        '#b25e03' => self::RUST,
        '#579bfc' => self::BLUE,
        '#0086c0' => self::BLUE,
        '#225091' => self::BLUE,
        '#0f5d97' => self::BLUE,
        '#4f6bed' => self::BLUE,
        '#1a7fa8' => self::TEAL,
        '#0f8f7e' => self::TEAL,
        '#175a63' => self::DEEP_TEAL,
        '#66ccff' => self::AQUA,
        '#66cccc' => self::AQUA,
        '#4eccc6' => self::AQUA,
        '#a25ddc' => self::LAVENDER,
        '#784bd1' => self::LAVENDER,
        '#5559df' => self::LAVENDER,
        '#a24ec9' => self::LAVENDER,
        '#9d99b9' => self::LAVENDER,
        '#401694' => self::PLUM,
        '#5a3fc0' => self::PLUM,
        '#ff158a' => self::ROSE,
        '#ff5ac4' => self::ROSE,
        '#c94f8f' => self::ROSE,
        '#c94f7c' => self::ROSE,
        '#ffadad' => self::ROSE,
        '#333333' => self::CHARCOAL,
        '#323338' => self::CHARCOAL,
        '#563e3e' => self::CHARCOAL,
        '#808080' => self::SLATE,
        '#c4c4c4' => self::SLATE,
        '#9aa0b6' => self::SLATE,
        '#797e93' => self::SLATE,
    ];

    /**
     * Tonk => the original color it stands for. Crimson and Deep teal go back to
     * the old Critical and Pending defaults, which were never in the picker.
     *
     * @var array<string, string>
     */
    private const TO_ORIGINAL = [
        self::RED => ColorPalette::RED,
        self::CRIMSON => '#b02f43',
        self::RUST => ColorPalette::BROWN,
        self::ORANGE => ColorPalette::ORANGE,
        self::AMBER => ColorPalette::AMBER,
        self::MOSS => ColorPalette::FOREST,
        self::GREEN => ColorPalette::GREEN,
        self::SAGE => ColorPalette::JADE,
        self::AQUA => ColorPalette::LIGHT_BLUE,
        self::TEAL => ColorPalette::OCEAN,
        self::DEEP_TEAL => '#1f5b9e',
        self::BLUE => ColorPalette::BLUE,
        self::ROSE => ColorPalette::PINK,
        self::LAVENDER => ColorPalette::VIOLET,
        self::PLUM => ColorPalette::PLUM,
        self::SAND => ColorPalette::MUSTARD,
        self::SLATE => ColorPalette::SLATE,
        self::CHARCOAL => ColorPalette::CHARCOAL,
    ];

    /** The Tonk color for a known original or monday.com color, null for Tonk colors and custom picks. */
    public static function fromColor(?string $hex): ?string
    {
        return $hex === null ? null : self::TO_TONK[strtolower($hex)] ?? null;
    }

    /** The original color a Tonk color stands for, null when the color is not one of Tonk's. */
    public static function toOriginal(?string $hex): ?string
    {
        return $hex === null ? null : self::TO_ORIGINAL[strtolower($hex)] ?? null;
    }
}
