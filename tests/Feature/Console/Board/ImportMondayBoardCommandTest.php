<?php

use App\Models\BoardColumn;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds a small synthetic monday.com-style export mirroring the real file's
 * layout (title row, group title row, grey header row, item rows, a
 * subitem block, a second group), so the import command's row-classification
 * logic is exercised without depending on the real customer data file.
 */
function writeMondayFixture(string $path): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('board');

    $header = ['Name', 'Subitems', 'Assignee', 'Priority', 'Status', 'Ernesto - Kaban', 'Timeline - Start', 'Timeline - End', 'Working Branch', 'Points /3', 'Tools', 'Project', 'Goal Completion Date', 'Text', 'Item ID (auto generated)'];
    $subitem_header = ['Subitems', 'Name', 'Owner', 'Status', 'Date', 'Item ID (auto generated)'];

    $rows = [
        ['Test Roadmap'],
        ['Group One'],
        $header,
        ['Fix login bug', '', 'Jane Doe', 'High', 'Working on it', 'Backlog', '', '', '', '2', 'backend, urgent', 'Website', '', 'Some notes', '1111111111'],
        ['Ship feature', 'Subtask A', 'Jane Doe', '', 'Done', '', '', '', '', '', '', '', '', '', '2222222222'],
        $subitem_header,
        ['', 'Subtask A', 'Jane Doe', 'Done', '', '3333333333'],
        [],
        ['Group Two'],
        $header,
        ['Second group item', '', '', '', '', '', '', '', '', '', '', '', '', '', '4444444444'],
    ];

    $sheet->fromArray($rows, null, 'A1');

    foreach ([3, 10] as $header_row) {
        $sheet->getStyle('A'.$header_row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D6D6D6');
    }

    (new Xlsx($spreadsheet))->save($path);
}

test('imports groups, items, subitems and column values from a monday.com export', function () {
    $workspace = Workspace::factory()->create(['name' => 'Import Test Workspace']);
    $jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', [
        'file' => $path,
        '--workspace' => $workspace->slug,
    ])->assertExitCode(0);

    @unlink($path);

    $board = WorkspaceNavigationItem::where('workspace_id', $workspace->id)
        ->where('label', 'Test Roadmap')
        ->first();

    expect($board)->not->toBeNull();
    expect($board->type)->toBe(WorkspaceNavigationItem::TYPE_LEAF);
    expect($board->views()->where('is_primary', true)->count())->toBe(1);
    expect($board->groups()->count())->toBe(2);
    expect($board->items()->whereNull('parent_id')->count())->toBe(3);
    expect($board->items()->whereNotNull('parent_id')->count())->toBe(1);

    $item = $board->items()->where('name', 'Fix login bug')->firstOrFail();
    $assignee_column = $board->columns()->where('scope', BoardColumn::SCOPE_ITEM)->where('key', 'assignee')->firstOrFail();
    $priority_column = $board->columns()->where('scope', BoardColumn::SCOPE_ITEM)->where('key', 'priority')->firstOrFail();
    $tools_column = $board->columns()->where('scope', BoardColumn::SCOPE_ITEM)->where('key', 'tools')->firstOrFail();

    $assignee_value = $item->values()->where('column_id', $assignee_column->id)->firstOrFail();
    expect($assignee_value->value)->toBe([(string) $jane->id]);

    $priority_option = collect($priority_column->config['options'])->firstWhere('label', 'High');
    $priority_value = $item->values()->where('column_id', $priority_column->id)->firstOrFail();
    expect($priority_value->value)->toBe($priority_option['id']);

    $tool_labels = collect($tools_column->config['options'])->pluck('label')->all();
    expect($tool_labels)->toEqualCanonicalizing(['backend', 'urgent']);

    $subitem = $board->items()->where('name', 'Subtask A')->firstOrFail();
    $parent_item = $board->items()->where('name', 'Ship feature')->firstOrFail();
    expect($subitem->parent_id)->toBe($parent_item->id);
    expect($subitem->group_id)->toBe($parent_item->group_id);
});

test('replaces an existing board with the same name when --force is passed', function () {
    $workspace = Workspace::factory()->create(['name' => 'Import Replace Workspace']);

    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug])->assertExitCode(0);
    $first_board_id = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Test Roadmap')->firstOrFail()->id;

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug, '--force' => true])->assertExitCode(0);

    @unlink($path);

    $boards = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Test Roadmap')->get();
    expect($boards)->toHaveCount(1);
    expect($boards->first()->id)->not->toBe($first_board_id);
});

test('does nothing when the workspace slug does not exist', function () {
    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => 'does-not-exist'])
        ->assertExitCode(1);

    @unlink($path);

    expect(WorkspaceNavigationItem::where('label', 'Test Roadmap')->exists())->toBeFalse();
});
