<?php

use App\Support\ColorPalette;

test('the palette holds the original 18 status colors', function () {
    expect(ColorPalette::COLORS)->toBe([
        '#2074d4', '#1071c4', '#3a8fc9', '#7b3f98', '#7b52c9', '#12c46b',
        '#12724a', '#2f9e78', '#f2a53c', '#f2662a', '#e04455', '#f453b0',
        '#8a6248', '#3a3a3a', '#9dd6f2', '#6b7189', '#e2b203', '#c9ccd4',
    ]);
});
