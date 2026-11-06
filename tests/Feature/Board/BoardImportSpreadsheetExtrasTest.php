<?php

use App\Models\BoardColumn;
use App\Models\BoardImportJob;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function createExtrasImportBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true]);

    return [$board, $view];
}

function addListValidation(Worksheet $sheet, string $range, string $options): void
{
    $validation = new DataValidation;
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setFormula1('"'.$options.'"');
    $validation->setAllowBlank(true);
    $validation->setShowDropDown(true);
    $sheet->setDataValidation($range, $validation);
}

function fakePngPath(int $width = 40, int $height = 30): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 87, 155, 252));
    $path = tempnam(sys_get_temp_dir(), 'import_png_').'.png';
    imagepng($image, $path);

    return $path;
}

/**
 * A feedback tracker like the client's: Status first, a description column
 * that names the items, dropdown lists (one with options no row uses), a
 * priority list, TRUE/FALSE checkboxes pre-filled far below the data,
 * screenshots over the "Screenshot" cells (one anchored to the cell on its
 * left with an offset as wide as that cell), and a second sheet.
 */
function fakeFeedbackWorkbook(): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Feedback');
    $sheet->fromArray([
        ['Status', 'Implemented?', 'Type', 'Feedback Description', 'Priority', 'Screenshot', 'Reported By'],
        ['Done', true, 'Bug Fix', 'Typing in the status editor is glitchy', 'Low', null, 'Amanda'],
        ['In Progress', true, 'Feature Request', 'Add a Save button to the people picker', 'High', null, 'Amanda'],
        ['Done', false, 'Bug Fix', 'Keep me logged in does not work', 'Medium', null, 'Kennedy'],
    ], null, 'A1', true);

    // A checkbox column pre-filled with FALSE far below the data.
    foreach (range(5, 40) as $row) {
        $sheet->setCellValue("B{$row}", false);
    }

    addListValidation($sheet, 'A2:A40', 'Open,In Progress,Done,Stuck');
    addListValidation($sheet, 'C2:C40', 'Bug Fix,Feature Request,Other');
    addListValidation($sheet, 'E2:E40', 'High,Medium,Low');
    $sheet->getColumnDimension('E')->setWidth(12);

    $first = new Drawing;
    $first->setName('image1.png');
    $first->setPath(fakePngPath());
    $first->setCoordinates('F2');
    $first->setWorksheet($sheet);

    // Anchored to E3, but its offset is wider than column E, so it sits over F3.
    $second = new Drawing;
    $second->setName('image2.png');
    $second->setPath(fakePngPath(60, 20));
    $second->setCoordinates('E3');
    $second->setOffsetX(95);
    $second->setWorksheet($sheet);

    $other = $spreadsheet->createSheet();
    $other->setTitle('Timeline');
    $other->fromArray([
        ['Goal', 'Target Date'],
        ['All teams added', '2026-11-01'],
    ], null, 'A1', true);

    $path = tempnam(sys_get_temp_dir(), 'import_test_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, '97_Workspace_Feedback.xlsx', null, null, true);
}

test('analyze lists the sheets, skips FALSE-only rows and reads dropdown lists and screenshots', function () {
    Storage::fake(config('filesystems.app_disk'));
    $user = User::factory()->create();
    [$board, $view] = createExtrasImportBoard();

    $response = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFeedbackWorkbook(),
        'view_id' => $view->id,
    ])->assertOk()
        ->assertJsonPath('row_count', 3)
        ->assertJsonPath('sheet_index', 0)
        ->assertJsonPath('sheets.0.name', 'Feedback')
        ->assertJsonPath('sheets.0.row_count', 3)
        ->assertJsonPath('sheets.0.image_count', 2)
        ->assertJsonPath('sheets.1.name', 'Timeline');

    $columns = collect($response->json('source_columns'))->keyBy('label');

    expect($columns['Status']['suggested_type'])->toBe(BoardColumn::TYPE_STATUS)
        ->and(array_column($columns['Status']['declared_options'], 'label'))->toBe(['Open', 'In Progress', 'Done', 'Stuck'])
        ->and($columns['Implemented?']['suggested_type'])->toBe(BoardColumn::TYPE_CHECKBOX)
        ->and($columns['Type']['suggested_type'])->toBe(BoardColumn::TYPE_DROPDOWN)
        ->and(array_column($columns['Type']['declared_options'], 'label'))->toBe(['Bug Fix', 'Feature Request', 'Other'])
        ->and($columns['Priority']['suggested_type'])->toBe(BoardColumn::TYPE_STATUS)
        ->and($columns['Priority']['option_preset'])->toBe('priority')
        ->and(collect($columns['Priority']['declared_options'])->firstWhere('label', 'High')['color'])->toBe('#401694')
        ->and($columns['Screenshot']['suggested_type'])->toBe(BoardColumn::TYPE_FILES)
        ->and($columns['Screenshot']['filled_count'])->toBe(2)
        ->and($columns['Screenshot']['assets']['image_count'])->toBe(2)
        ->and($columns['Screenshot']['assets']['previews'][0]['url'])->toBeString();

    // The description names the items, not the Status column that comes first.
    $name_mapping = collect($response->json('suggested_mappings'))->firstWhere('mode', 'name');
    expect($name_mapping['source_index'])->toBe($columns['Feedback Description']['index']);
});

test('commit uploads embedded screenshots into a Files column and keeps every dropdown option', function () {
    Storage::fake(config('filesystems.app_disk'));
    $user = User::factory()->create();
    [$board, $view] = createExtrasImportBoard();

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFeedbackWorkbook(),
        'view_id' => $view->id,
    ])->assertOk()->json();

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'view_id' => $view->id,
        'new_group_name' => 'Feedback',
        'mappings' => $analyze['suggested_mappings'],
        'duplicate_mode' => 'add',
    ])->assertStatus(202)->assertJsonPath('data.status', BoardImportJob::STATUS_COMPLETED)->assertJsonPath('data.created_count', 3);

    $column = fn (string $label) => BoardColumn::where('board_view_id', $view->id)->where('label', $label)->firstOrFail();
    $item = $board->items()->where('name', 'Add a Save button to the people picker')->firstOrFail();

    $files = $item->values()->where('column_id', $column('Screenshot')->id)->value('value');
    expect($files)->toHaveCount(1)
        ->and($files[0]['kind'])->toBe('file')
        ->and($files[0]['mime_type'])->toBe('image/png')
        ->and($files[0]['file_name'])->toBe('screenshot-row-3.png')
        ->and($files[0]['path'])->toStartWith("board-cell-files/{$item->id}/")
        ->and($files[0]['width'])->toBe(60);
    Storage::disk(config('filesystems.app_disk'))->assertExists($files[0]['path']);

    $status_labels = array_column($column('Status')->config['options'], 'label');
    expect($status_labels)->toBe(['Open', 'In Progress', 'Done', 'Stuck']);

    $priority = $column('Priority');
    expect($priority->type)->toBe(BoardColumn::TYPE_STATUS)
        ->and(collect($priority->config['options'])->pluck('color', 'label')->all())->toBe(['High' => '#401694', 'Medium' => '#5559df', 'Low' => '#579bfc']);

    expect($column('Type')->type)->toBe(BoardColumn::TYPE_DROPDOWN)
        ->and(array_column($column('Type')->config['options'], 'label'))->toBe(['Bug Fix', 'Feature Request', 'Other'])
        ->and($item->values()->where('column_id', $column('Implemented?')->id)->value('value'))->toBeTrue();

    // The staged copies are removed once every file is attached.
    expect(Storage::disk(config('filesystems.app_disk'))->allFiles('board-imports'))->toBe([]);
});

test('switching sheets reads the other sheet and discards the previous upload', function () {
    Storage::fake(config('filesystems.app_disk'));
    $user = User::factory()->create();
    [$board, $view] = createExtrasImportBoard();

    $first = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFeedbackWorkbook(),
        'view_id' => $view->id,
    ])->assertOk()->json();

    expect(Storage::disk(config('filesystems.app_disk'))->allFiles("board-imports/{$first['import_token']}"))->toHaveCount(2);

    $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => fakeFeedbackWorkbook(),
        'view_id' => $view->id,
        'sheet_index' => 1,
        'discard_token' => $first['import_token'],
    ])->assertOk()
        ->assertJsonPath('sheet_index', 1)
        ->assertJsonPath('row_count', 1)
        ->assertJsonPath('source_columns.0.label', 'Goal');

    expect(Storage::disk(config('filesystems.app_disk'))->allFiles("board-imports/{$first['import_token']}"))->toBe([]);
});

test('a dropdown cell whose option contains a comma keeps it as one choice', function () {
    $user = User::factory()->create();
    [$board, $view] = createExtrasImportBoard();

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Title', 'Answer'],
        ['One', 'Yes, approved'],
        ['Two', 'No'],
        ['Three', 'Maybe'],
    ], null, 'A1', true);
    $list = $spreadsheet->createSheet();
    $list->setTitle('Lists');
    $list->fromArray([['Yes, approved'], ['No'], ['Maybe'], ['Later']], null, 'A1', true);
    $validation = new DataValidation;
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setFormula1("'Lists'!\$A\$1:\$A\$4");
    $sheet->setDataValidation('B2:B10', $validation);
    $path = tempnam(sys_get_temp_dir(), 'import_test_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => new UploadedFile($path, 'answers.xlsx', null, null, true),
        'view_id' => $view->id,
    ])->assertOk()->json();

    $answer = collect($analyze['source_columns'])->firstWhere('label', 'Answer');
    expect($answer['suggested_type'])->toBe(BoardColumn::TYPE_DROPDOWN)
        ->and(array_column($answer['declared_options'], 'label'))->toBe(['Yes, approved', 'No', 'Maybe', 'Later']);

    $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/import/commit", [
        'import_token' => $analyze['import_token'],
        'view_id' => $view->id,
        'mappings' => $analyze['suggested_mappings'],
        'duplicate_mode' => 'add',
    ])->assertStatus(202);

    $column = BoardColumn::where('board_view_id', $view->id)->where('label', 'Answer')->firstOrFail();
    $option_ids = $board->items()->where('name', 'One')->firstOrFail()->values()->where('column_id', $column->id)->value('value');

    expect($option_ids)->toHaveCount(1)
        ->and(collect($column->config['options'])->firstWhere('id', $option_ids[0])['label'])->toBe('Yes, approved')
        ->and($column->config['options'])->toHaveCount(4);
});

test('a description that mentions a link stays text and names the items', function () {
    $user = User::factory()->create();
    [$board, $view] = createExtrasImportBoard();

    $csv = "Status,Feedback Description\n"
        ."Done,\"The favicon is distorted. Please use the red icon from the brand folder instead - https://drive.google.com/open?id=1\"\n"
        ."Done,\"Profile pictures are not our real logo, use the primary profile picture instead - https://drive.google.com/open?id=2\"\n";
    $path = tempnam(sys_get_temp_dir(), 'import_test_').'.csv';
    file_put_contents($path, $csv);

    $analyze = $this->actingAs($user, 'api')->post("/api/boards/{$board->id}/import/analyze", [
        'file' => new UploadedFile($path, 'design.csv', 'text/csv', null, true),
        'view_id' => $view->id,
    ])->assertOk()->json();

    expect($analyze['source_columns'][1]['suggested_type'])->toBe(BoardColumn::TYPE_LONG_TEXT)
        ->and(collect($analyze['suggested_mappings'])->firstWhere('mode', 'name')['source_index'])->toBe(1);
});
