<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\ColorPalette;
use App\Support\TonkPalette;

beforeEach(function () {
    $workspace = Workspace::factory()->create();
    $this->board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $this->folder = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'parent_id' => null,
        'color' => '#00c875',
    ]);
    $this->group = BoardGroup::factory()->create(['board_id' => $this->board->id, 'accent_color' => '#579bfc']);
    $this->status = BoardColumn::factory()->create([
        'board_id' => $this->board->id,
        'type' => BoardColumn::TYPE_STATUS,
        'config' => ['options' => [
            ['id' => 'done', 'label' => 'Done', 'color' => ColorPalette::GREEN],
            ['id' => 'prod', 'label' => 'In Prod', 'color' => ColorPalette::FOREST],
            ['id' => 'custom', 'label' => 'Custom', 'color' => '#123456'],
            ['id' => 'tonk', 'label' => 'Already Tonk', 'color' => TonkPalette::TEAL],
        ]],
    ]);
    $this->button = BoardColumn::factory()->create([
        'board_id' => $this->board->id,
        'type' => BoardColumn::TYPE_BUTTON,
        'config' => ['button_label' => 'Click me', 'button_color' => '#579bfc'],
    ]);
    $this->tag = $this->board->tags()->create(['label' => '#seo', 'color' => ColorPalette::RED, 'position' => 0]);
});

test('known colors move onto Tonk\'s palette, custom and Tonk colors stay', function () {
    $this->artisan('board:apply-tonk-colors')->assertSuccessful();

    expect(collect($this->status->fresh()->config['options'])->pluck('color')->all())
        ->toBe([TonkPalette::GREEN, TonkPalette::MOSS, '#123456', TonkPalette::TEAL])
        ->and($this->button->fresh()->config['button_color'])->toBe(TonkPalette::BLUE)
        ->and($this->tag->fresh()->color)->toBe(TonkPalette::RED)
        ->and($this->group->fresh()->accent_color)->toBe(TonkPalette::BLUE)
        ->and($this->folder->fresh()->color)->toBe(TonkPalette::GREEN);
});

test('running it twice changes nothing the second time', function () {
    $this->artisan('board:apply-tonk-colors')->assertSuccessful();
    $config = $this->status->fresh()->config;

    $this->artisan('board:apply-tonk-colors', ['--dry-run' => true])
        ->expectsTable(['Record', 'Would change'], [['options', 0], ['buttons', 0], ['tags', 0], ['groups', 0], ['folders', 0]])
        ->assertSuccessful();
    expect($this->status->fresh()->config)->toBe($config);
});

test('the rollback command restores what apply changed', function () {
    $this->artisan('board:apply-tonk-colors')->assertSuccessful();
    $this->artisan('board:remap-palette-colors')->assertSuccessful();

    expect(collect($this->status->fresh()->config['options'])->pluck('color')->all())
        ->toBe([ColorPalette::GREEN, ColorPalette::FOREST, '#123456', ColorPalette::OCEAN])
        ->and($this->tag->fresh()->color)->toBe(ColorPalette::RED);
});

test('a dry run writes nothing', function () {
    $this->artisan('board:apply-tonk-colors', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing was written.')
        ->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe(ColorPalette::GREEN)
        ->and($this->folder->fresh()->color)->toBe('#00c875');
});

test('--only limits the run to the given record types', function () {
    $this->artisan('board:apply-tonk-colors', ['--only' => ['tags', 'groups']])->assertSuccessful();

    expect($this->tag->fresh()->color)->toBe(TonkPalette::RED)
        ->and($this->group->fresh()->accent_color)->toBe(TonkPalette::BLUE)
        ->and($this->status->fresh()->config['options'][0]['color'])->toBe(ColorPalette::GREEN)
        ->and($this->button->fresh()->config['button_color'])->toBe('#579bfc')
        ->and($this->folder->fresh()->color)->toBe('#00c875');
});

test('an unknown --only type is refused', function () {
    $this->artisan('board:apply-tonk-colors', ['--only' => ['colums']])
        ->expectsOutputToContain('Unknown record type: colums')
        ->assertFailed();
});

test('--board limits the run to the given boards and skips folders', function () {
    $this->artisan('board:apply-tonk-colors', ['--board' => [$this->board->id]])->assertSuccessful();

    expect($this->status->fresh()->config['options'][0]['color'])->toBe(TonkPalette::GREEN)
        ->and($this->folder->fresh()->color)->toBe('#00c875');
});
