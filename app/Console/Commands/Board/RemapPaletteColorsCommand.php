<?php

namespace App\Console\Commands\Board;

use App\Support\TonkPalette;

/**
 * Rolls colors saved from Tonk's palette back to the original palette
 * ({@see TonkPalette::toOriginal()}). The inverse of {@see ApplyTonkColorsCommand}.
 * Any color that is not one of Tonk's is left alone, so running it twice is safe.
 */
// php artisan board:remap-palette-colors --board=4767811559 --only=options --dry-run
class RemapPaletteColorsCommand extends RemapBoardColorsCommand
{
    protected $signature = 'board:remap-palette-colors'.self::SHARED_OPTIONS;

    protected $description = 'Move status, label, tag, group and folder colors saved from the Tonk palette back to the original palette';

    protected function remappedColor(string $color): ?string
    {
        return TonkPalette::toOriginal($color);
    }
}
