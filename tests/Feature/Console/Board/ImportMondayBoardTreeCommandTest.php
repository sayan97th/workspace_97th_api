<?php

use App\Models\BoardColumn;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes a small monday.com-style export at $path, named $title, with one group and one item
 * owned by $owner_name — just enough to exercise the directory-tree command's file discovery
 * and group-folder mapping without depending on real customer data.
 */
function writeMondayTreeFixture(string $path, string $title, string $owner_name = 'Jane Doe'): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('board');

    $header = ['Name', 'Owner', 'Status', 'Item ID (auto generated)'];

    $rows = [
        [$title],
        ['Group One'],
        $header,
        ['First task', $owner_name, 'Working on it', '1111111111'],
    ];

    $sheet->fromArray($rows, null, 'A1');

    $sheet->getStyle('A3')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('D6D6D6');

    (new Xlsx($spreadsheet))->save($path);
}

/**
 * @return string the temp root directory
 */
function writeMondayTreeFixtures(): string
{
    $root = sys_get_temp_dir().'/monday-tree-import-test-'.uniqid();

    mkdir($root.'/Folder One/Sub Folder', recursive: true);
    mkdir($root.'/Folder Two', recursive: true);

    writeMondayTreeFixture($root.'/Folder One/Sub Folder/Board A.xlsx', 'Board A');
    writeMondayTreeFixture($root.'/Folder Two/Board B.xlsx', 'Board B');
    writeMondayTreeFixture($root.'/Board C.xlsx', 'Board C');

    return $root;
}

function deleteDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }

    rmdir($dir);
}

test('imports a directory tree, mapping subfolders to navigation folders and files to boards', function () {
    $workspace = Workspace::factory()->create(['name' => 'Tree Import Workspace']);
    $jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $root = writeMondayTreeFixtures();

    $this->artisan('board:import-monday-directory', [
        'directory' => $root,
        '--workspace' => $workspace->slug,
    ])->assertExitCode(0);

    deleteDirectory($root);

    $folder_one = WorkspaceNavigationItem::where('workspace_id', $workspace->id)
        ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
        ->where('label', 'Folder One')
        ->whereNull('parent_id')
        ->firstOrFail();

    $sub_folder = WorkspaceNavigationItem::where('workspace_id', $workspace->id)
        ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
        ->where('label', 'Sub Folder')
        ->where('parent_id', $folder_one->id)
        ->firstOrFail();

    $board_a = WorkspaceNavigationItem::where('workspace_id', $workspace->id)
        ->where('label', 'Board A')
        ->firstOrFail();
    expect($board_a->type)->toBe(WorkspaceNavigationItem::TYPE_LEAF);
    expect($board_a->parent_id)->toBe($sub_folder->id);

    $folder_two = WorkspaceNavigationItem::where('workspace_id', $workspace->id)
        ->where('type', WorkspaceNavigationItem::TYPE_GROUP)
        ->where('label', 'Folder Two')
        ->whereNull('parent_id')
        ->firstOrFail();

    $board_b = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board B')->firstOrFail();
    expect($board_b->parent_id)->toBe($folder_two->id);

    // A file sitting directly in the root of the given directory becomes a root-level board.
    $board_c = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board C')->firstOrFail();
    expect($board_c->parent_id)->toBeNull();

    expect($board_a->groups()->count())->toBe(1);
    expect($board_a->items()->count())->toBe(1);

    $owner_column = $board_a->columns()->where('scope', BoardColumn::SCOPE_ITEM)->where('key', 'owner')->firstOrFail();
    expect($owner_column->type)->toBe(BoardColumn::TYPE_PEOPLE);

    $item = $board_a->items()->firstOrFail();
    $owner_value = $item->values()->where('column_id', $owner_column->id)->firstOrFail();
    expect($owner_value->value)->toBe([(string) $jane->id]);
});

test('skips a board that already exists in its folder without --force, and replaces it with --force', function () {
    $workspace = Workspace::factory()->create(['name' => 'Tree Import Skip Workspace']);
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $root = writeMondayTreeFixtures();

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => $workspace->slug])
        ->assertExitCode(0);

    $first_id = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board A')->firstOrFail()->id;

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => $workspace->slug])
        ->assertExitCode(0);

    $unchanged = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board A')->get();
    expect($unchanged)->toHaveCount(1);
    expect($unchanged->first()->id)->toBe($first_id);

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => $workspace->slug, '--force' => true])
        ->assertExitCode(0);

    deleteDirectory($root);

    $replaced = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board A')->get();
    expect($replaced)->toHaveCount(1);
    expect($replaced->first()->id)->not->toBe($first_id);
});

test('dry run parses every file without writing anything to the database', function () {
    $workspace = Workspace::factory()->create(['name' => 'Tree Import Dry Run Workspace']);
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $root = writeMondayTreeFixtures();

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => $workspace->slug, '--dry-run' => true])
        ->assertExitCode(0);

    deleteDirectory($root);

    expect(WorkspaceNavigationItem::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('keeps importing the rest of the batch when one file fails', function () {
    $workspace = Workspace::factory()->create(['name' => 'Tree Import Partial Failure Workspace']);
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $root = writeMondayTreeFixtures();

    // Corrupt one of the three fixtures (not a real .xlsx) — the other two should still import.
    file_put_contents($root.'/Folder Two/Board B.xlsx', 'not a real spreadsheet');

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => $workspace->slug])
        ->assertExitCode(0);

    deleteDirectory($root);

    expect(WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board A')->exists())->toBeTrue();
    expect(WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board C')->exists())->toBeTrue();
    expect(WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Board B')->exists())->toBeFalse();
});

test('does nothing when the workspace slug does not exist', function () {
    $root = writeMondayTreeFixtures();

    $this->artisan('board:import-monday-directory', ['directory' => $root, '--workspace' => 'does-not-exist'])
        ->assertExitCode(1);

    deleteDirectory($root);

    expect(WorkspaceNavigationItem::where('label', 'Board A')->exists())->toBeFalse();
});
