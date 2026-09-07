<?php

use App\Models\BoardColumn;
use App\Models\BoardItemComment;
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

    $updates_sheet = $spreadsheet->createSheet();
    $updates_sheet->setTitle('updates');
    $updates_header = ['Item ID', 'Item Name', 'Content Type', 'Content Type', 'User', 'Created At', 'Update Content', 'Likes Count', 'Asset IDs', 'Post ID', 'Parent Post ID'];
    $updates_rows = [
        ['Test Roadmap', 'Updates'],
        $updates_header,
        // Top-level update on "Fix login bug" (item id 1111111111), with a credential line.
        ['1111111111', 'Fix login bug', 'Update', '', 'Jane Doe', '08/July/2021 05:23:52 PM', "Access notes:\nPW: super-secret-123\nLogin URL: https://example.com", '0', '', '9000000001', ''],
        // A reply to that same thread.
        ['1111111111', 'Fix login bug', '', 'Reply', 'Jane Doe', '21/July/2021 02:44:08 AM', 'Rotated the password already.', '0', '', '9000000002', '9000000001'],
        // Top-level update on the subitem (item id 3333333333), no credential.
        ['3333333333', 'Subtask A', 'Update', '', 'Jane Doe', '09/July/2021 09:00:00 AM', 'All done here.', '0', '', '9000000003', ''],
        // Update referencing an item id that was never imported (should be skipped).
        ['5555555555', 'Unknown item', 'Update', '', 'Jane Doe', '09/July/2021 09:00:00 AM', 'Orphan update.', '0', '', '9000000004', ''],
    ];
    $updates_sheet->fromArray($updates_rows, null, 'A1');

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

test('skips the "updates" sheet by default', function () {
    $workspace = Workspace::factory()->create(['name' => 'Import Skip Updates Workspace']);
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug])
        ->assertExitCode(0);

    @unlink($path);

    $board = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Test Roadmap')->firstOrFail();
    expect(BoardItemComment::whereIn('item_id', $board->items()->pluck('id'))->count())->toBe(0);
});

test('imports updates and redacts credential-looking lines with --updates=redact', function () {
    $workspace = Workspace::factory()->create(['name' => 'Import Redact Workspace']);
    $jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug, '--updates' => 'redact'])
        ->assertExitCode(0);

    @unlink($path);

    $board = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Test Roadmap')->firstOrFail();
    $item = $board->items()->where('name', 'Fix login bug')->firstOrFail();
    $subitem = $board->items()->where('name', 'Subtask A')->firstOrFail();

    expect(BoardItemComment::whereIn('item_id', $board->items()->pluck('id'))->count())->toBe(3);

    $top_level = $item->comments()->whereNull('parent_id')->firstOrFail();
    expect($top_level->user_id)->toBe($jane->id);
    expect($top_level->body)->toContain('[REDACTED]');
    expect($top_level->body)->not->toContain('super-secret-123');
    expect($top_level->created_at->toDateString())->toBe('2021-07-08');

    $reply = $item->comments()->whereNotNull('parent_id')->firstOrFail();
    expect($reply->parent_id)->toBe($top_level->id);
    expect($reply->body)->toBe('Rotated the password already.');

    expect($subitem->comments()->count())->toBe(1);
});

test('drops comment threads that contain a credential with --updates=exclude', function () {
    $workspace = Workspace::factory()->create(['name' => 'Import Exclude Workspace']);
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug, '--updates' => 'exclude'])
        ->assertExitCode(0);

    @unlink($path);

    $board = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Test Roadmap')->firstOrFail();
    $item = $board->items()->where('name', 'Fix login bug')->firstOrFail();
    $subitem = $board->items()->where('name', 'Subtask A')->firstOrFail();

    expect($item->comments()->count())->toBe(0);
    expect($subitem->comments()->count())->toBe(1);
});

test('does nothing when the workspace slug does not exist', function () {
    $path = sys_get_temp_dir().'/monday-import-test-'.uniqid().'.xlsx';
    writeMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => 'does-not-exist'])
        ->assertExitCode(1);

    @unlink($path);

    expect(WorkspaceNavigationItem::where('label', 'Test Roadmap')->exists())->toBeFalse();
});
