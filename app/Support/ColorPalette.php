<?php

namespace App\Support;

/**
 * The app's one color palette, designed by Tonk for 97th Floor. Every color the
 * API assigns on its own (default Status/Label options, new groups, imported
 * option colors, backfilled tags) comes from here, so it always matches what the
 * frontend's pickers offer. Mirrors `src/lib/color-palette.ts` on the frontend;
 * keep both in sync.
 */
final class ColorPalette
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
     * Every palette color, in the picker's order (6 per row).
     *
     * @var array<int, string>
     */
    public const COLORS = [
        self::RED, self::CRIMSON, self::RUST, self::ORANGE, self::AMBER, self::MOSS,
        self::GREEN, self::SAGE, self::AQUA, self::TEAL, self::DEEP_TEAL, self::BLUE,
        self::ROSE, self::LAVENDER, self::PLUM, self::SAND, self::SLATE, self::CHARCOAL,
    ];

    /**
     * Order used when a color is picked automatically: contrasting hues first,
     * quieter neutrals last. Same order as the frontend's `PALETTE_CYCLE`.
     *
     * @var array<int, string>
     */
    public const CYCLE = [
        self::GREEN, self::AMBER, self::RED, self::BLUE, self::PLUM, self::ORANGE,
        self::TEAL, self::ROSE, self::MOSS, self::LAVENDER, self::RUST, self::AQUA,
        self::DEEP_TEAL, self::SAND, self::CRIMSON, self::SAGE, self::SLATE, self::CHARCOAL,
    ];

    /**
     * monday.com colors the app used to offer (and the old board defaults), each
     * paired by eye with its Tonk counterpart, so a remap keeps the meaning a
     * team gave a color: Stuck stays red, Done stays green, Testing stays apart
     * from Ready to start. Matches the frontend's old to new default labels.
     *
     * @var array<string, string>
     */
    private const LEGACY_COLORS = [
        '#7f5347' => self::RUST,
        '#b25e03' => self::RUST,
        '#8a6248' => self::RUST,
        '#037f4c' => self::MOSS,
        '#12724a' => self::MOSS,
        '#2f9e78' => self::MOSS,
        '#00c875' => self::GREEN,
        '#9cd326' => self::GREEN,
        '#12c46b' => self::GREEN,
        '#3faf5a' => self::GREEN,
        '#cab641' => self::AMBER,
        '#e2a200' => self::AMBER,
        '#fdab3d' => self::AMBER,
        '#ffcb00' => self::AMBER,
        '#f2a53c' => self::AMBER,
        '#e2b203' => self::AMBER,
        '#e8b23a' => self::AMBER,
        '#8a6d1f' => self::AMBER,
        '#c9a227' => self::SAND,
        '#a25ddc' => self::LAVENDER,
        '#784bd1' => self::LAVENDER,
        '#5559df' => self::LAVENDER,
        '#7b52c9' => self::LAVENDER,
        '#a24ec9' => self::LAVENDER,
        '#9d99b9' => self::LAVENDER,
        '#401694' => self::PLUM,
        '#7b3f98' => self::PLUM,
        '#5a3fc0' => self::PLUM,
        '#5b3fbd' => self::PLUM,
        '#0086c0' => self::BLUE,
        '#579bfc' => self::BLUE,
        '#225091' => self::BLUE,
        '#0f5d97' => self::BLUE,
        '#2074d4' => self::BLUE,
        '#3a8fc9' => self::BLUE,
        '#4f6bed' => self::BLUE,
        '#1071c4' => self::TEAL,
        '#1a7fa8' => self::TEAL,
        '#0f8f7e' => self::TEAL,
        '#175a63' => self::DEEP_TEAL,
        '#1f5b9e' => self::DEEP_TEAL,
        '#66ccff' => self::AQUA,
        '#66cccc' => self::AQUA,
        '#4eccc6' => self::AQUA,
        '#9dd6f2' => self::AQUA,
        '#e2445c' => self::RED,
        '#e04455' => self::RED,
        '#ff7575' => self::RED,
        '#bb3354' => self::CRIMSON,
        '#b03060' => self::CRIMSON,
        '#b02f43' => self::CRIMSON,
        '#ff158a' => self::ROSE,
        '#ff5ac4' => self::ROSE,
        '#f453b0' => self::ROSE,
        '#c94f8f' => self::ROSE,
        '#c94f7c' => self::ROSE,
        '#ffadad' => self::ROSE,
        '#ff642e' => self::ORANGE,
        '#f2662a' => self::ORANGE,
        '#d4763a' => self::ORANGE,
        '#e0723f' => self::ORANGE,
        '#333333' => self::CHARCOAL,
        '#323338' => self::CHARCOAL,
        '#3a3a3a' => self::CHARCOAL,
        '#563e3e' => self::CHARCOAL,
        '#808080' => self::SLATE,
        '#c4c4c4' => self::SLATE,
        '#c9ccd4' => self::SLATE,
        '#6b7189' => self::SLATE,
        '#9aa0b6' => self::SLATE,
        '#5b6180' => self::SLATE,
        '#797e93' => self::SLATE,
    ];

    /** Palette colors that read as neutral, the only targets for a gray when remapping. */
    private const NEUTRALS = [self::SLATE, self::CHARCOAL];

    /** Below this chroma (CIELAB) a color is treated as gray. */
    private const NEUTRAL_CHROMA = 12.0;

    /** The color for the nth automatically colored record, wrapping around {@see CYCLE}. */
    public static function cycle(int $index): string
    {
        $count = count(self::CYCLE);

        return self::CYCLE[(($index % $count) + $count) % $count];
    }

    public static function contains(?string $hex): bool
    {
        return $hex !== null && in_array(strtolower($hex), self::COLORS, true);
    }

    /**
     * The palette color to use instead of the given one, used to move colors
     * saved from the old palette onto Tonk's. A known monday.com color uses its
     * curated pair ({@see LEGACY_COLORS}); any other color the closest one by
     * perceived difference (CIE76 in CIELAB), where grays only map to the neutral
     * swatches so an empty looking label never turns into Sand. Returns null when
     * the value is not a `#rrggbb` color.
     */
    public static function nearest(?string $hex): ?string
    {
        if ($hex === null || ! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            return null;
        }

        $hex = strtolower($hex);
        if (self::contains($hex)) {
            return $hex;
        }
        if (isset(self::LEGACY_COLORS[$hex])) {
            return self::LEGACY_COLORS[$hex];
        }

        $source = self::toLab($hex);
        $candidates = hypot($source[1], $source[2]) < self::NEUTRAL_CHROMA ? self::NEUTRALS : self::COLORS;

        $best_color = $candidates[0];
        $best_distance = INF;
        foreach ($candidates as $candidate) {
            $distance = self::perceivedDistance($source, self::toLab($candidate));
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best_color = $candidate;
            }
        }

        return $best_color;
    }

    /**
     * Squared CIE76 difference of two CIELAB colors.
     *
     * @param  array{0: float, 1: float, 2: float}  $first
     * @param  array{0: float, 1: float, 2: float}  $second
     */
    private static function perceivedDistance(array $first, array $second): float
    {
        return ($first[0] - $second[0]) ** 2 + ($first[1] - $second[1]) ** 2 + ($first[2] - $second[2]) ** 2;
    }

    /**
     * sRGB hex to CIELAB (D65).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toLab(string $hex): array
    {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        [$red, $green, $blue] = $channels;
        $x = ($red * 0.4124 + $green * 0.3576 + $blue * 0.1805) / 0.95047;
        $y = ($red * 0.2126 + $green * 0.7152 + $blue * 0.0722) / 1.0;
        $z = ($red * 0.0193 + $green * 0.1192 + $blue * 0.9505) / 1.08883;

        $pivot = fn (float $value): float => $value > 0.008856 ? $value ** (1 / 3) : (7.787 * $value) + (16 / 116);
        [$fx, $fy, $fz] = [$pivot($x), $pivot($y), $pivot($z)];

        return [(116 * $fy) - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }
}
