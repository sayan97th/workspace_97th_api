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
    $this->group = BoardGroup::factory()->create(['board_id' => $this->board->id, 'accent_color' => '#579bfc']);
    $this->status = BoardColumn::factory()->create([
        'board_id' => $this->board->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
            ['id' => 'stuck', 'label' => 'Stuck', 'color' => '#e2445c'],
            ['id' => 'moss', 'label' => 'In Prod', 'color' => ColorPalette::MOSS],
        ]],
    ]);
    $this->tag = $this->board->tags()->create(['label' => '#seo', 'color' => '#fdab3d', 'position' => 0]);
});

test('saved colors move to the closest palette color, palette colors stay', function () {
    $this->artisan('board:remap-palette-colors')->assertSuccessful();

    expect(collect($this->status->fresh()->config['options'])->pluck('color')->all())
        ->toBe([ColorPalette::GREEN, ColorPalette::RED, ColorPalette::MOSS])
        ->and($this->tag->fresh()->color)->toBe(ColorPalette::AMBER)
        ->and($this->group->fresh()->accent_color)->toBe(ColorPalette::BLUE);
});

test('a dry run writes nothing', function () {
    $this->artisan('board:remap-palette-colors', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing was written.')
        ->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe('#00c875')
        ->and($this->tag->fresh()->color)->toBe('#fdab3d');
});

test('--board limits the remap to the given boards', function () {
    $this->artisan('board:remap-palette-colors', ['--board' => [$this->board->id + 1000]])->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe('#00c875');
});
