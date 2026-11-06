<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardImportJob;
use App\Models\BoardView;
use App\Models\User;

/**
 * The "Map columns" step can import one file column into several board
 * columns (its "Duplicate" action and the "Add column from file" button)
 * and leave others out entirely. Helpers come from BoardImportTest.php.
 */
function analyzeDuplicateColumnsCsv($test, User $user, int $board_id): array
{
    $csv = "Name,Feedback Description,Notes\n"
        ."Task One,Typing is glitchy,Check on Safari\n"
        ."Task Two,Favicon is distorted,\n";

    return $test->actingAs($user, 'api')->post("/api/boards/{$board_id}/import/analyze", [
        'file' => fakeFlatCsvUpload($csv),
    ])->json();
}

test('one file column can be imported into several new columns, the name included', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();
    $analyze = analyzeDuplicateColumnsCsv($this, $user, $board->id);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'new_group_name' => 'Feedback',
        'mappings' => [
            ['source_index' => 0, 'mode' => 'name'],
            ['source_index' => 0, 'mode' => 'create', 'new_label' => 'Original name', 'new_type' => 'text'],
            ['source_index' => 1, 'mode' => 'create', 'new_label' => 'Feedback Description', 'new_type' => 'long_text'],
            ['source_index' => 1, 'mode' => 'create', 'new_label' => 'Feedback Description 2', 'new_type' => 'text'],
            ['source_index' => 2, 'mode' => 'skip'],
        ],
        'duplicate_mode' => 'add',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED)
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.columns_created', 3);

    $group = BoardGroup::where('board_id', $board->id)->firstOrFail();
    $columns = BoardColumn::where('board_view_id', $group->board_view_id)->orderBy('position')->get();

    // Created in the order of the rows, the skipped "Notes" column is never created.
    expect($columns->pluck('label')->all())->toBe(['Original name', 'Feedback Description', 'Feedback Description 2'])
        ->and($columns->pluck('type')->all())->toBe(['text', 'long_text', 'text'])
        ->and($columns->pluck('key')->unique()->count())->toBe(3);

    $first_item = $group->items()->orderBy('position')->with('values')->first();
    $valueOf = fn (BoardColumn $column) => $first_item->values->firstWhere('column_id', $column->id)?->value;

    expect($first_item->name)->toBe('Task One')
        ->and($valueOf($columns[0]))->toBe('Task One')
        ->and($valueOf($columns[2]))->toBe('Typing is glitchy')
        ->and($valueOf($columns[1]))->not->toBeNull();
});

test('two rows mapped to the same board column only write it once, and matching uses the first one', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);
    $description = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'scope' => BoardColumn::SCOPE_ITEM,
        'label' => 'Description',
        'type' => BoardColumn::TYPE_TEXT,
    ]);

    $existing = $board->items()->create(['group_id' => $group->id, 'name' => 'Old name', 'position' => 1]);
    $existing->values()->create(['column_id' => $description->id, 'value' => 'Typing is glitchy']);

    $analyze = analyzeDuplicateColumnsCsv($this, $user, $board->id);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'view_id' => $view->id,
        'target_group_id' => $group->id,
        'mappings' => [
            ['source_index' => 0, 'mode' => 'name'],
            ['source_index' => 1, 'mode' => 'map', 'target_column_id' => $description->id],
            ['source_index' => 2, 'mode' => 'map', 'target_column_id' => $description->id],
            ['source_index' => 1, 'mode' => 'create', 'new_label' => 'Copy', 'new_type' => 'text'],
        ],
        'duplicate_mode' => 'update',
        'match_source_index' => 1,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED)
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.created_count', 1)
        ->assertJsonPath('data.columns_created', 1);

    $existing->refresh()->load('values');
    expect($existing->name)->toBe('Task One')
        // "Notes" was the second row mapped to Description, so it never overwrote the first.
        ->and($existing->values->firstWhere('column_id', $description->id)?->value)->toBe('Typing is glitchy');
});
