<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\ColorPalette;

beforeEach(function () {
    $workspace = Workspace::factory()->create();
    $this->board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $this->group = BoardGroup::factory()->create(['board_id' => $this->board->id, 'accent_color' => '#2783c5']);
    $this->status = BoardColumn::factory()->create([
        'board_id' => $this->board->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Done', 'color' => '#60943f'],
            ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e53e2e'],
            ['id' => 'monday', 'label' => 'Working on it', 'color' => '#fdab3d'],
        ]],
    ]);
    $this->tag = $this->board->tags()->create(['label' => '#seo', 'color' => '#e29703', 'position' => 0]);
});

test('Tonk colors roll back to the original palette, other colors stay', function () {
    $this->artisan('board:remap-palette-colors')->assertSuccessful();

    expect(collect($this->status->fresh()->config['options'])->pluck('color')->all())
        ->toBe([ColorPalette::GREEN, ColorPalette::RED, '#fdab3d'])
        ->and($this->tag->fresh()->color)->toBe(ColorPalette::AMBER)
        ->and($this->group->fresh()->accent_color)->toBe(ColorPalette::BLUE);
});

test('a dry run writes nothing', function () {
    $this->artisan('board:remap-palette-colors', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing was written.')
        ->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe('#60943f')
        ->and($this->tag->fresh()->color)->toBe('#e29703');
});

test('--board limits the remap to the given boards', function () {
    $this->artisan('board:remap-palette-colors', ['--board' => [$this->board->id + 1000]])->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe('#60943f');
});
