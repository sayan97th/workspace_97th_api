<?php

use App\Support\ColorPalette;
use App\Support\TonkPalette;

test('Tonk\'s palette holds the 18 colors of the design', function () {
    expect(TonkPalette::COLORS)->toBe([
        '#e53e2e', '#800f12', '#ab350d', '#d55b08', '#e29703', '#567460',
        '#60943f', '#8fac87', '#8cd1cb', '#0499b1', '#2c6776', '#2783c5',
        '#e697b2', '#9a7ab6', '#7f2b81', '#c9b893', '#7e8889', '#2b3c40',
    ]);
});

test('fromColor maps original and monday colors to Tonk by meaning', function (string $color, string $tonk) {
    expect(TonkPalette::fromColor($color))->toBe($tonk);
})->with([
    'original Done green' => [ColorPalette::GREEN, TonkPalette::GREEN],
    'original Stuck red, any case' => ['#E04455', TonkPalette::RED],
    'original In Prod forest' => [ColorPalette::FOREST, TonkPalette::MOSS],
    'old Pending default' => ['#1f5b9e', TonkPalette::DEEP_TEAL],
    'old Critical label' => ['#b02f43', TonkPalette::CRIMSON],
    'monday Done green' => ['#00c875', TonkPalette::GREEN],
    'monday Working on it' => ['#fdab3d', TonkPalette::AMBER],
    'monday light gray' => ['#c4c4c4', TonkPalette::SLATE],
]);

test('fromColor leaves Tonk colors and custom picks alone', function () {
    expect(TonkPalette::fromColor(TonkPalette::RED))->toBeNull()
        ->and(TonkPalette::fromColor('#123456'))->toBeNull()
        ->and(TonkPalette::fromColor(null))->toBeNull();
});

test('every Tonk color has an original, and every original color has a Tonk color', function () {
    foreach (TonkPalette::COLORS as $tonk) {
        expect(TonkPalette::toOriginal($tonk))->not->toBeNull();
    }
    foreach (ColorPalette::COLORS as $original) {
        expect(TonkPalette::fromColor($original))->toBeIn(TonkPalette::COLORS);
    }
});

test('applying Tonk and rolling back returns the original colors, except Sky and Silver', function () {
    $round_trip = fn (string $hex) => TonkPalette::toOriginal(TonkPalette::fromColor($hex));

    foreach (array_diff(ColorPalette::COLORS, [ColorPalette::SKY, ColorPalette::SILVER]) as $original) {
        expect($round_trip($original))->toBe($original);
    }
    expect($round_trip(ColorPalette::SKY))->toBe(ColorPalette::BLUE)
        ->and($round_trip(ColorPalette::SILVER))->toBe(ColorPalette::SLATE)
        ->and($round_trip('#1f5b9e'))->toBe('#1f5b9e')
        ->and($round_trip('#b02f43'))->toBe('#b02f43');
});
