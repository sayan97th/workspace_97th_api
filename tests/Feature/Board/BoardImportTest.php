<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function createImportTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

function fakeFlatCsvUpload(string $contents = "Name,Status,Owner\nTask One,Done,Ernesto Afane\nTask Two,Working on it,\n"): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import_test_').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'tasks.csv', 'text/csv', null, true);
}

test('analyze parses a flat csv upload and returns a token', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $response = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ]);

    $response->assertOk()
        ->assertJsonPath('row_count', 2)
        ->assertJsonCount(3, 'source_columns')
        ->assertJsonPath('source_columns.0.label', 'Name')
        ->assertJsonPath('source_columns.1.label', 'Status')
        ->assertJsonPath('suggested_mappings.0.mode', 'name');

    expect($response->json('import_token'))->toBeString()->not->toBe('');
});

test('commit creates a new table and items with created columns', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $mappings = collect($analyze['source_columns'])->map(fn (array $column) => $column['label'] === 'Name'
        ? ['source_index' => $column['index'], 'mode' => 'name']
        : ['source_index' => $column['index'], 'mode' => 'create', 'new_label' => $column['label'], 'new_type' => $column['suggested_type']])
        ->values()->all();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'new_group_name' => 'Imported table',
        'mappings' => $mappings,
        'duplicate_mode' => 'add',
    ]);

    $response->assertOk()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('columns_created', 2)
        ->assertJsonPath('group_created', true);

    $group = BoardGroup::where('board_id', $board->id)->firstOrFail();
    expect($group->name)->toBe('Imported table')
        ->and($group->items()->count())->toBe(2)
        ->and(BoardColumn::where('board_view_id', $group->board_view_id)->count())->toBe(2);

    $first_item = $group->items()->orderBy('position')->first();
    expect($first_item->name)->toBe('Task One');
});

test('duplicate mode "update" overwrites a matching item instead of creating a new one', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $post_analyze = fn () => $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $mappings = fn (array $analyze) => collect($analyze['source_columns'])->map(fn (array $column) => $column['label'] === 'Name'
        ? ['source_index' => $column['index'], 'mode' => 'name']
        : ['source_index' => $column['index'], 'mode' => 'create', 'new_label' => $column['label'], 'new_type' => $column['suggested_type']])
        ->values()->all();

    $first_analyze = $post_analyze();
    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $first_analyze['import_token'],
        'new_group_name' => 'Tasks',
        'mappings' => $mappings($first_analyze),
        'duplicate_mode' => 'add',
    ])->assertOk();

    $group = BoardGroup::where('board_id', $board->id)->firstOrFail();

    // Re-import the same two names, one with a changed Status, in "update" mode.
    $second_analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload("Name,Status,Owner\nTask One,Stuck,Ernesto Afane\nTask Two,Done,\n"),
    ])->json();

    $second_mappings = collect($second_analyze['source_columns'])->map(function (array $column) use ($group) {
        if ($column['label'] === 'Name') {
            return ['source_index' => $column['index'], 'mode' => 'name'];
        }
        $existing = BoardColumn::where('board_view_id', $group->board_view_id)->where('label', $column['label'])->first();

        return ['source_index' => $column['index'], 'mode' => 'map', 'target_column_id' => $existing->id];
    })->values()->all();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $second_analyze['import_token'],
        'target_group_id' => $group->id,
        'mappings' => $second_mappings,
        'duplicate_mode' => 'update',
    ]);

    $response->assertOk()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('updated', 2)
        ->assertJsonPath('group_created', false);

    expect($group->items()->count())->toBe(2);
});

test('duplicate mode "skip" leaves a matching item untouched', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $first_analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $mappings = collect($first_analyze['source_columns'])->map(fn (array $column) => $column['label'] === 'Name'
        ? ['source_index' => $column['index'], 'mode' => 'name']
        : ['source_index' => $column['index'], 'mode' => 'create', 'new_label' => $column['label'], 'new_type' => $column['suggested_type']])
        ->values()->all();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $first_analyze['import_token'],
        'new_group_name' => 'Tasks',
        'mappings' => $mappings,
        'duplicate_mode' => 'add',
    ])->assertOk();

    $group = BoardGroup::where('board_id', $board->id)->firstOrFail();

    $second_analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $second_mappings = collect($second_analyze['source_columns'])->map(fn (array $column) => ['source_index' => $column['index'], 'mode' => $column['label'] === 'Name' ? 'name' : 'skip'])
        ->values()->all();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $second_analyze['import_token'],
        'target_group_id' => $group->id,
        'mappings' => $second_mappings,
        'duplicate_mode' => 'skip',
    ]);

    $response->assertOk()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('skipped', 2);

    expect($group->items()->count())->toBe(2);
});

test('commit with an expired or unknown import token is rejected', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => (string) Str::uuid(),
        'new_group_name' => 'Tasks',
        'mappings' => [['source_index' => 0, 'mode' => 'name']],
        'duplicate_mode' => 'add',
    ]);

    $response->assertStatus(410);
});
