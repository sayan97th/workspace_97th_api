<?php

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

test('a navigation item gets a random 10-digit id instead of a sequential one', function () {
    $workspace = Workspace::factory()->create();

    $first = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id]);
    $second = WorkspaceNavigationItem::factory()->create(['workspace_id' => $workspace->id]);

    foreach ([$first, $second] as $item) {
        expect($item->id)->toBeGreaterThanOrEqual(1_000_000_000)
            ->and($item->id)->toBeLessThanOrEqual(9_999_999_999);
    }

    // Not sequential: the second id shouldn't just be the first plus one.
    expect($second->id)->not->toBe($first->id + 1);
});

test('every navigation item id is unique, even across a larger batch', function () {
    $workspace = Workspace::factory()->create();

    $ids = WorkspaceNavigationItem::factory()
        ->count(25)
        ->create(['workspace_id' => $workspace->id])
        ->pluck('id');

    expect($ids->unique())->toHaveCount(25);
});
