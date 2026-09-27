<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: BoardItem, 1: BoardColumn}
 */
function createCellFileTestItem(string $column_type = BoardColumn::TYPE_FILES): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => $column_type]);
    $board_item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    return [$board_item, $column];
}

function cellFilesUrl(BoardItem $board_item, BoardColumn $column): string
{
    return "/api/boards/{$board_item->board_id}/items/{$board_item->id}/columns/{$column->id}/files";
}

test('a file can be uploaded into a files cell and is tagged as a file', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$board_item, $column] = createCellFileTestItem();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column), [
            'files' => [UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'files')
        ->assertJsonPath('files.0.kind', 'file')
        ->assertJsonPath('files.0.file_name', 'brief.pdf');
});

test('a link can be added to a files cell with its display text', function () {
    [$board_item, $column] = createCellFileTestItem();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', [
            'url' => 'https://www.figma.com/file/abc',
            'text' => 'Design file V1',
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'files')
        ->assertJsonPath('files.0.kind', 'link')
        ->assertJsonPath('files.0.url', 'https://www.figma.com/file/abc')
        ->assertJsonPath('files.0.file_name', 'Design file V1')
        ->assertJsonPath('files.0.size_bytes', 0);

    $stored = $board_item->values()->where('column_id', $column->id)->first()->value;
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['kind'])->toBe('link');
});

test('a link without display text falls back to the url, and a bare domain gets a scheme', function () {
    [$board_item, $column] = createCellFileTestItem();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', ['url' => 'example.com/report.pdf'])
        ->assertCreated()
        ->assertJsonPath('files.0.url', 'https://example.com/report.pdf')
        ->assertJsonPath('files.0.file_name', 'https://example.com/report.pdf');
});

test('a link is appended after the files already in the cell', function () {
    [$board_item, $column] = createCellFileTestItem();
    $board_item->values()->create([
        'column_id' => $column->id,
        'value' => [['id' => 'existing', 'file_name' => 'old.pdf', 'path' => 'x/old.pdf', 'url' => 'https://cdn.test/old.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]],
    ]);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', ['url' => 'https://miro.com/board/1'])
        ->assertCreated()
        ->assertJsonCount(2, 'files')
        ->assertJsonPath('files.0.id', 'existing')
        ->assertJsonPath('files.1.kind', 'link');
});

test('an invalid or unsafe link is rejected', function (string $url) {
    [$board_item, $column] = createCellFileTestItem();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', ['url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
})->with([
    'empty' => [''],
    'javascript scheme' => ['javascript://alert(1)'],
    'ftp scheme' => ['ftp://files.test/a.pdf'],
    'not a url' => ['not a url at all'],
]);

test('a link cannot be added to a column that is not a files column', function () {
    [$board_item, $column] = createCellFileTestItem(BoardColumn::TYPE_TEXT);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', ['url' => 'https://example.com'])
        ->assertNotFound();
});

test('deleting a link removes it without touching storage', function () {
    Storage::fake(config('filesystems.app_disk'));
    [$board_item, $column] = createCellFileTestItem();
    $user = User::factory()->create();

    $link_id = $this->actingAs($user, 'api')
        ->postJson(cellFilesUrl($board_item, $column).'/link', ['url' => 'https://example.com'])
        ->json('files.0.id');

    $this->actingAs($user, 'api')
        ->deleteJson(cellFilesUrl($board_item, $column)."/{$link_id}")
        ->assertOk()
        ->assertJsonCount(0, 'files');
});
