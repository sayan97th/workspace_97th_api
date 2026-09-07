<?php

use App\Jobs\ProcessBoardImportJob;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardImportJob;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\BoardItemImportService;
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

/**
 * Every "mode" => "create" mapping shorthand, used by most of the tests
 * below — the test suite runs with QUEUE_CONNECTION=sync (see phpunit.xml),
 * so ProcessBoardImportJob::dispatch() inside the commit() call runs to
 * completion before the HTTP response comes back, meaning `data.status` is
 * already a terminal status by the time these tests inspect it.
 */
function createMappingsFor(array $source_columns): array
{
    return collect($source_columns)->map(fn (array $column) => $column['label'] === 'Name'
        ? ['source_index' => $column['index'], 'mode' => 'name']
        : ['source_index' => $column['index'], 'mode' => 'create', 'new_label' => $column['label'], 'new_type' => $column['suggested_type']])
        ->values()->all();
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

test('commit queues a background job that creates a new table and items', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'new_group_name' => 'Imported table',
        'mappings' => createMappingsFor($analyze['source_columns']),
        'duplicate_mode' => 'add',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED)
        ->assertJsonPath('data.total_rows', 2)
        ->assertJsonPath('data.processed_rows', 2)
        ->assertJsonPath('data.percent', 100)
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.columns_created', 2);

    $group = BoardGroup::where('board_id', $board->id)->firstOrFail();
    expect($group->name)->toBe('Imported table')
        ->and($group->items()->count())->toBe(2)
        ->and(BoardColumn::where('board_view_id', $group->board_view_id)->count())->toBe(2);

    $first_item = $group->items()->orderBy('position')->first();
    expect($first_item->name)->toBe('Task One');

    // The token's cached upload is cleaned up once the job finishes.
    expect(app(BoardItemImportService::class)->loadParsedImport($analyze['import_token']))->toBeNull();
});

test('GET the import job returns the same status the commit response did', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $commit = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'new_group_name' => 'Tasks',
        'mappings' => createMappingsFor($analyze['source_columns']),
        'duplicate_mode' => 'add',
    ])->json();

    $response = $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/import/{$commit['data']['id']}");

    $response->assertOk()->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED);
});

test('duplicate mode "update" overwrites a matching item instead of creating a new one', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $first_analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $first_analyze['import_token'],
        'new_group_name' => 'Tasks',
        'mappings' => createMappingsFor($first_analyze['source_columns']),
        'duplicate_mode' => 'add',
    ])->assertStatus(202);

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

    $response->assertStatus(202)
        ->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED)
        ->assertJsonPath('data.created_count', 0)
        ->assertJsonPath('data.updated_count', 2);

    expect($group->items()->count())->toBe(2);
});

test('duplicate mode "skip" leaves a matching item untouched', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $first_analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $first_analyze['import_token'],
        'new_group_name' => 'Tasks',
        'mappings' => createMappingsFor($first_analyze['source_columns']),
        'duplicate_mode' => 'add',
    ])->assertStatus(202);

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

    $response->assertStatus(202)
        ->assertJsonPath('data.created_count', 0)
        ->assertJsonPath('data.skipped_count', 2);

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

test('a job already cancelled before it starts processing creates nothing', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFlatCsvUpload(),
    ])->json();

    $import_job = BoardImportJob::create([
        'board_id' => $board->id,
        'board_view_id' => $board->views()->firstOrFail()->id,
        'user_id' => $user->id,
        'import_token' => $analyze['import_token'],
        'file_name' => 'tasks.csv',
        'status' => BoardImportJob::STATUS_QUEUED,
        'total_rows' => 2,
        'cancel_requested' => true,
        'options' => [
            'target_group_id' => null,
            'new_group_name' => 'Tasks',
            'mappings' => createMappingsFor($analyze['source_columns']),
            'duplicate_mode' => 'add',
            'match_source_index' => null,
        ],
    ]);

    app(ProcessBoardImportJob::class, ['board_import_job_id' => $import_job->id])->handle(app(BoardItemImportService::class));

    expect($import_job->fresh()->status)->toBe(BoardImportJob::STATUS_CANCELLED)
        ->and(BoardGroup::where('board_id', $board->id)->count())->toBe(0);
});

test('the cancel endpoint flags a still-processing job without touching a completed one', function () {
    $user = User::factory()->create();
    $board = createImportTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id]);

    $processing_job = BoardImportJob::create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'user_id' => $user->id,
        'import_token' => (string) Str::uuid(),
        'file_name' => 'tasks.csv',
        'status' => BoardImportJob::STATUS_PROCESSING,
        'total_rows' => 100,
        'options' => ['target_group_id' => null, 'new_group_name' => 'Tasks', 'mappings' => [], 'duplicate_mode' => 'add', 'match_source_index' => null],
    ]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/{$processing_job->id}/cancel");
    $response->assertOk()->assertJsonPath('data.cancel_requested', true);

    $completed_job = BoardImportJob::create([
        'board_id' => $board->id,
        'board_view_id' => $processing_job->board_view_id,
        'user_id' => $user->id,
        'import_token' => (string) Str::uuid(),
        'file_name' => 'tasks.csv',
        'status' => BoardImportJob::STATUS_COMPLETED,
        'total_rows' => 2,
        'processed_rows' => 2,
        'options' => ['target_group_id' => null, 'new_group_name' => 'Tasks', 'mappings' => [], 'duplicate_mode' => 'add', 'match_source_index' => null],
    ]);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/{$completed_job->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.cancel_requested', false);
});
