<?php

use App\Support\ColorPalette;

test('the palette holds Tonk\'s 18 colors and the cycle uses each once', function () {
    expect(ColorPalette::COLORS)->toHaveCount(18)
        ->and(collect(ColorPalette::CYCLE)->sort()->values()->all())->toBe(collect(ColorPalette::COLORS)->sort()->values()->all());
});

test('cycle wraps around in both directions', function () {
    expect(ColorPalette::cycle(0))->toBe(ColorPalette::GREEN)
        ->and(ColorPalette::cycle(18))->toBe(ColorPalette::GREEN)
        ->and(ColorPalette::cycle(-1))->toBe(ColorPalette::CHARCOAL);
});

test('nearest keeps palette colors and maps old monday colors by hue', function (string $old, string $expected) {
    expect(ColorPalette::nearest($old))->toBe($expected);
})->with([
    'palette color, any case' => ['#E53E2E', ColorPalette::RED],
    'monday done green' => ['#00c875', ColorPalette::GREEN],
    'monday working orange' => ['#fdab3d', ColorPalette::AMBER],
    'monday stuck red' => ['#e2445c', ColorPalette::RED],
    'monday purple' => ['#a25ddc', ColorPalette::LAVENDER],
    'monday pink' => ['#ff158a', ColorPalette::ROSE],
    'old In Prod green' => ['#12724a', ColorPalette::MOSS],
    'old Testing blue stays apart from Ready to start' => ['#1071c4', ColorPalette::TEAL],
    'unknown wine falls back to the closest color' => ['#7a1f3d', ColorPalette::CRIMSON],
    'unknown sea green falls back to the closest color' => ['#3cb371', ColorPalette::GREEN],
    'monday light gray' => ['#c4c4c4', ColorPalette::SLATE],
    'monday dark gray' => ['#333333', ColorPalette::CHARCOAL],
]);

test('nearest ignores values that are not hex colors', function () {
    expect(ColorPalette::nearest(null))->toBeNull()
        ->and(ColorPalette::nearest('red'))->toBeNull()
        ->and(ColorPalette::nearest('#fff'))->toBeNull();
});
