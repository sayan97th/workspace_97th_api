<?php

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardTag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A monday.com-style export exercising every column type the importer can
 * detect, plus the row layouts that used to be misclassified: an item row
 * right after a subitem block whose "Hours" cell sits in the subitem header's
 * "Item ID" column, a group summary row, and two subitem headers naming
 * different columns.
 */
function writeTypedMondayFixture(string $path): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('board');

    $item_header = [
        'Name', 'Subitems', 'Owner', 'Status', 'People', 'Hours', 'Due Date', 'Created at', 'Unplanned', 'Contact email',
        'Notes Link', 'Files', 'Product(s)', 'Tags', 'Timeline - Start', 'Timeline - End', 'Legacy ID', 'Item ID (auto generated)',
    ];

    $rows = [
        ['Type Detection Board'],
        ['Group One'],
        $item_header,
        [
            'Alpha', '', 'Done', 'Working on it', 'Jane Doe', '5', null, 'May 2, 2024 3:04 PM', 'v', 'alpha@example.com',
            'Spec - https://example.com/spec', 'https://97work.monday.com/protected_static/1/resources/2/Mask Group-1.png',
            'SEO, Content', 'urgent, backend', '2024-01-01', '2024-01-31', '8396967148', '1000000001',
        ],
        ['Subitems', 'Name', 'Status', 'Owner', 'Estimate', 'Item ID (auto generated)'],
        ['', 'Sub A', 'Done', 'Jane Doe', '3', '1000000002'],
        [
            'Beta', '', 'SEO', 'Done', 'Jane Doe', '5', null, 'Apr 17, 2023 11:41 AM', '', 'beta@example.com',
            'https://example.com/beta', '', 'SEO', 'urgent', '2024-02-01', '', '8345080496', '1000000003',
        ],
        ['Subitems', 'Name', 'Status', 'Checkbox', 'Item ID (auto generated)'],
        ['', 'Sub B', 'Stuck', 'v', '1000000004'],
        ['', '', '', '', '', '34'],
        [
            'Gamma', '', 'Done', 'Done', '', '2', null, 'Feb 18, 2025 1:38 PM', 'v', 'gamma@example.com',
            'Doc - https://example.com/g', '', 'Ads', '', '', '', '8365173510', '1000000005',
        ],
    ];

    $sheet->fromArray($rows, null, 'A1', true);

    // Real Excel date cells, displayed in a US locale format — the importer
    // must read the underlying date, not the display text.
    foreach (['G4' => '2024-03-15', 'G7' => '2024-04-01', 'G11' => '2024-05-20'] as $coordinate => $date) {
        $sheet->setCellValue($coordinate, SpreadsheetDate::PHPToExcel(new DateTime($date)));
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('m/d/yyyy');
    }

    (new Xlsx($spreadsheet))->save($path);
}

/**
 * @return array{0: WorkspaceNavigationItem, 1: User}
 */
function importTypedMondayFixture(object $test): array
{
    $workspace = Workspace::factory()->create();
    $jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-typed-import-'.uniqid().'.xlsx';
    writeTypedMondayFixture($path);

    $test->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug])->assertExitCode(0);

    @unlink($path);

    $board = WorkspaceNavigationItem::where('workspace_id', $workspace->id)->where('label', 'Type Detection Board')->firstOrFail();

    return [$board, $jane];
}

function importedColumn(WorkspaceNavigationItem $board, string $key, string $scope = BoardColumn::SCOPE_ITEM): BoardColumn
{
    return $board->columns()->where('scope', $scope)->where('key', $key)->firstOrFail();
}

function importedValue(BoardItem $item, BoardColumn $column): mixed
{
    return $item->values()->where('column_id', $column->id)->first()?->value;
}

/**
 * @return array<int, string>
 */
function optionLabels(BoardColumn $column, mixed $ids): array
{
    $labels = collect($column->fresh()->config['options'] ?? [])->pluck('label', 'id');

    return collect((array) $ids)->map(fn (string $id) => $labels[$id] ?? null)->all();
}

test('classifies item and subitem rows by their monday.com ids, skipping summary rows', function () {
    [$board] = importTypedMondayFixture($this);

    expect($board->items()->whereNull('parent_id')->orderBy('position')->pluck('name')->all())->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($board->items()->whereNotNull('parent_id')->pluck('name')->all())->toEqualCanonicalizing(['Sub A', 'Sub B'])
        ->and($board->items()->where('name', 'Untitled subitem')->exists())->toBeFalse();

    $beta = $board->items()->where('name', 'Beta')->firstOrFail();
    expect($board->items()->where('name', 'Sub B')->firstOrFail()->parent_id)->toBe($beta->id);
});

test('creates the union of every subitem header\'s columns', function () {
    [$board] = importTypedMondayFixture($this);

    $subitem_types = $board->columns()->where('scope', BoardColumn::SCOPE_SUBITEM)->pluck('type', 'key')->all();

    expect($subitem_types)->toBe([
        'status' => BoardColumn::TYPE_STATUS,
        'owner' => BoardColumn::TYPE_PEOPLE,
        'estimate' => BoardColumn::TYPE_NUMBER,
        'checkbox' => BoardColumn::TYPE_CHECKBOX,
    ]);

    $sub_b = $board->items()->where('name', 'Sub B')->firstOrFail();
    expect(importedValue($sub_b, importedColumn($board, 'checkbox', BoardColumn::SCOPE_SUBITEM)))->toBeTrue();
});

test('detects each column type from its values rather than its header alone', function () {
    [$board] = importTypedMondayFixture($this);

    expect($board->columns()->where('scope', BoardColumn::SCOPE_ITEM)->pluck('type', 'key')->all())->toBe([
        'timeline' => BoardColumn::TYPE_TIMELINE,
        // Named like a person column, but its values are statuses.
        'owner' => BoardColumn::TYPE_STATUS,
        'status' => BoardColumn::TYPE_STATUS,
        'people' => BoardColumn::TYPE_PEOPLE,
        'hours' => BoardColumn::TYPE_NUMBER,
        'due_date' => BoardColumn::TYPE_DATE,
        'created_at' => BoardColumn::TYPE_DATE,
        'unplanned' => BoardColumn::TYPE_CHECKBOX,
        'contact_email' => BoardColumn::TYPE_EMAIL,
        'notes_link' => BoardColumn::TYPE_LINK,
        'files' => BoardColumn::TYPE_FILES,
        'product_s' => BoardColumn::TYPE_DROPDOWN,
        'tags' => BoardColumn::TYPE_TAGS,
        'legacy_id' => BoardColumn::TYPE_TEXT,
    ]);
});

test('stores every value in the shape its column type reads', function () {
    [$board, $jane] = importTypedMondayFixture($this);

    $alpha = $board->items()->where('name', 'Alpha')->firstOrFail();
    $beta = $board->items()->where('name', 'Beta')->firstOrFail();

    expect(importedValue($alpha, importedColumn($board, 'people')))->toBe([(string) $jane->id])
        ->and(importedValue($alpha, importedColumn($board, 'hours')))->toBe(5)
        ->and(importedValue($alpha, importedColumn($board, 'due_date')))->toBe('2024-03-15')
        ->and(importedValue($alpha, importedColumn($board, 'created_at')))->toBe('2024-05-02T15:04')
        ->and(importedValue($alpha, importedColumn($board, 'unplanned')))->toBeTrue()
        ->and(importedValue($beta, importedColumn($board, 'unplanned')))->toBeNull()
        ->and(importedValue($alpha, importedColumn($board, 'contact_email')))->toBe('alpha@example.com')
        ->and(importedValue($alpha, importedColumn($board, 'notes_link')))->toBe(['url' => 'https://example.com/spec', 'text' => 'Spec'])
        ->and(importedValue($beta, importedColumn($board, 'notes_link')))->toBe(['url' => 'https://example.com/beta', 'text' => 'https://example.com/beta'])
        ->and(importedValue($alpha, importedColumn($board, 'legacy_id')))->toBe('8396967148')
        ->and(importedValue($alpha, importedColumn($board, 'timeline')))->toBe(['start' => '2024-01-01', 'end' => '2024-01-31'])
        // Only one side of the range was filled in — kept as a single day rather than dropped.
        ->and(importedValue($beta, importedColumn($board, 'timeline')))->toBe(['start' => '2024-02-01', 'end' => '2024-02-01']);

    $owner_column = importedColumn($board, 'owner');
    expect(optionLabels($owner_column, importedValue($beta, $owner_column)))->toBe(['SEO']);

    $product_column = importedColumn($board, 'product_s');
    expect(optionLabels($product_column, importedValue($alpha, $product_column)))->toBe(['SEO', 'Content']);

    $files = importedValue($alpha, importedColumn($board, 'files'));
    expect($files)->toHaveCount(1)
        ->and($files[0]['kind'])->toBe('link')
        ->and($files[0]['url'])->toBe('https://97work.monday.com/protected_static/1/resources/2/Mask%20Group-1.png')
        ->and($files[0]['file_name'])->toBe('Mask Group-1.png');
});

test('writes Tags as board-wide tags shared across items', function () {
    [$board] = importTypedMondayFixture($this);

    $tags_column = importedColumn($board, 'tags');
    $tag_labels = BoardTag::where('board_id', $board->id)->pluck('label', 'id');

    expect($tags_column->config)->toBeNull()
        ->and($tag_labels->values()->all())->toEqualCanonicalizing(['urgent', 'backend']);

    $alpha_tags = importedValue($board->items()->where('name', 'Alpha')->firstOrFail(), $tags_column);
    $beta_tags = importedValue($board->items()->where('name', 'Beta')->firstOrFail(), $tags_column);

    expect(collect($alpha_tags)->map(fn (string $id) => $tag_labels[$id])->all())->toBe(['urgent', 'backend'])
        ->and($beta_tags)->toBe([$alpha_tags[0]]);
});

test('--columns prints each detected column with the reason it was chosen', function () {
    $workspace = Workspace::factory()->create();
    User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $path = sys_get_temp_dir().'/monday-typed-import-'.uniqid().'.xlsx';
    writeTypedMondayFixture($path);

    $this->artisan('board:import-monday', ['file' => $path, '--workspace' => $workspace->slug, '--dry-run' => true, '--columns' => true])
        ->expectsOutputToContain('Values are email addresses')
        ->expectsOutputToContain('All 2 values are checkmarks')
        ->assertExitCode(0);

    @unlink($path);

    expect(WorkspaceNavigationItem::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});
