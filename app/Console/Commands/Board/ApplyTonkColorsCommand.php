<?php

namespace App\Console\Commands\Board;

use App\Support\TonkPalette;

/**
 * Moves saved colors onto Tonk's palette ({@see TonkPalette::fromColor()}):
 * original palette colors, old board defaults and monday.com colors each become
 * their Tonk counterpart (Done stays green, Stuck stays red). Custom colors and
 * colors already in Tonk's palette are left alone, so running it twice is safe.
 * {@see RemapPaletteColorsCommand} rolls it back.
 */
// php artisan board:apply-tonk-colors --board=4767811559 --only=options --dry-run
class ApplyTonkColorsCommand extends RemapBoardColorsCommand
{
    protected $signature = 'board:apply-tonk-colors'.self::SHARED_OPTIONS;

    protected $description = 'Move saved status, label, tag, group and folder colors onto the Tonk palette';

    protected function remappedColor(string $color): ?string
    {
        return TonkPalette::fromColor($color);
    }
}
