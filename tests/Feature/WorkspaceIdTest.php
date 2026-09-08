<?php

use App\Models\Workspace;

test('a workspace gets a random 10-digit id instead of a sequential one', function () {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();

    foreach ([$first, $second] as $workspace) {
        expect($workspace->id)->toBeGreaterThanOrEqual(1_000_000_000)
            ->and($workspace->id)->toBeLessThanOrEqual(9_999_999_999);
    }

    // Not sequential: the second id shouldn't just be the first plus one.
    expect($second->id)->not->toBe($first->id + 1);
});

test('every workspace id is unique, even across a larger batch', function () {
    $ids = Workspace::factory()->count(25)->create()->pluck('id');

    expect($ids->unique())->toHaveCount(25);
});
