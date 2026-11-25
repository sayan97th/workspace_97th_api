<?php

use App\Support\ColorPalette;

test('the palette holds the original 18 status colors', function () {
    expect(ColorPalette::COLORS)->toBe([
        '#2074d4', '#1071c4', '#3a8fc9', '#7b3f98', '#7b52c9', '#12c46b',
        '#12724a', '#2f9e78', '#f2a53c', '#f2662a', '#e04455', '#f453b0',
        '#8a6248', '#3a3a3a', '#9dd6f2', '#6b7189', '#e2b203', '#c9ccd4',
    ]);
});

test('fromTonk rolls a Tonk color back to the color it replaced', function (string $tonk, string $original) {
    expect(ColorPalette::fromTonk($tonk))->toBe($original);
})->with([
    'Done green' => ['#60943f', ColorPalette::GREEN],
    'Stuck red, any case' => ['#E53E2E', ColorPalette::RED],
    'In Prod moss' => ['#567460', ColorPalette::FOREST],
    'Pending deep teal' => ['#2c6776', '#1f5b9e'],
    'Critical crimson' => ['#800f12', '#b02f43'],
]);

test('fromTonk leaves every other color alone', function () {
    expect(ColorPalette::fromTonk('#00c875'))->toBeNull()
        ->and(ColorPalette::fromTonk(ColorPalette::BLUE))->toBeNull()
        ->and(ColorPalette::fromTonk(null))->toBeNull();
});
