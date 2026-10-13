<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardTag;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

beforeEach(function () {
    $workspace = Workspace::factory()->create();
    $this->board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $this->board->id]);
    $this->column = BoardColumn::factory()->create([
        'board_id' => $this->board->id,
        'type' => BoardColumn::TYPE_TAGS,
        'config' => ['options' => [['id' => 'design', 'label' => 'Design', 'color' => '#a25ddc']]],
    ]);
    $this->existing_tag = $this->board->tags()->create(['label' => '#backend', 'color' => '#579bfc', 'position' => 0]);

    $this->legacy_item = $this->board->items()->create(['group_id' => $group->id, 'name' => 'Legacy', 'position' => 0]);
    $this->legacy_cell = $this->legacy_item->values()->create(['column_id' => $this->column->id, 'value' => ['design', 'backend', 'urgent']]);

    $this->clean_item = $this->board->items()->create(['group_id' => $group->id, 'name' => 'Clean', 'position' => 1]);
    $this->clean_cell = $this->clean_item->values()->create(['column_id' => $this->column->id, 'value' => [(string) $this->existing_tag->id]]);
});

test('legacy tags cells become board tags and keep their ids', function () {
    $this->artisan('board:backfill-tags')->expectsOutputToContain('Created 2 tags and updated 1 cells.')->assertSuccessful();

    $design = BoardTag::where('board_id', $this->board->id)->where('label', '#Design')->first();
    $urgent = BoardTag::where('board_id', $this->board->id)->where('label', '#urgent')->first();

    expect($design?->color)->toBe('#a25ddc')
        ->and($urgent)->not->toBeNull()
        ->and($this->legacy_cell->fresh()->value)->toBe([(string) $design->id, (string) $this->existing_tag->id, (string) $urgent->id])
        ->and($this->clean_cell->fresh()->value)->toBe([(string) $this->existing_tag->id]);

    // Running it again finds nothing left to convert.
    $this->artisan('board:backfill-tags')->expectsOutputToContain('Created 0 tags and updated 0 cells.')->assertSuccessful();
    expect(BoardTag::where('board_id', $this->board->id)->count())->toBe(3);
});

test('a dry run writes nothing', function () {
    $this->artisan('board:backfill-tags', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run, would create 2 tags and update 1 cells.')
        ->assertSuccessful();

    expect(BoardTag::where('board_id', $this->board->id)->count())->toBe(1)
        ->and($this->legacy_cell->fresh()->value)->toBe(['design', 'backend', 'urgent']);
});
