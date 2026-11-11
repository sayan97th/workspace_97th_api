<?php

use App\Models\BoardColumn;
use App\Models\BoardExportJob;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Covers "Export board to Excel" and the group menu's "Export to Excel": the export is queued
 * (sync in tests), written by ProcessBoardExportJob and downloaded through a signed link.
 */
function createExportBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Launch Plan',
    ]);
    $first_group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Design', 'position' => 0, 'accent_color' => '#579bfc']);
    $second_group = BoardGroup::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $first_group->board_view_id,
        'name' => 'Build',
        'position' => 1,
    ]);

    return [$board, $first_group, $second_group];
}

function exportColumn(WorkspaceNavigationItem $board, BoardGroup $group, string $type, string $label, int $position, ?array $config = null): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $group->board_view_id,
        'scope' => BoardColumn::SCOPE_ITEM,
        'type' => $type,
        'label' => $label,
        'position' => $position,
        'config' => $config,
    ]);
}

function exportValue(BoardItem $item, BoardColumn $column, mixed $value): void
{
    BoardItemValue::create(['item_id' => $item->id, 'column_id' => $column->id, 'value' => $value]);
}

/** The finished export's first sheet, read back with PhpSpreadsheet. */
function exportedSheet(BoardExportJob $export_job, int $index = 0): Worksheet
{
    $path = Storage::disk($export_job->disk)->path($export_job->file_path);

    return IOFactory::load($path)->getSheet($index);
}

/** @return array<int, string> every non empty value of a sheet's first column */
function firstColumnValues(Worksheet $sheet): array
{
    return collect($sheet->rangeToArray('A1:A'.$sheet->getHighestRow()))->pluck(0)->filter()->map(fn ($value) => (string) $value)->values()->all();
}

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
});

test('exporting a board queues a job that writes every column type in a readable way', function () {
    [$board, $group] = createExportBoard();
    $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    $status = exportColumn($board, $group, BoardColumn::TYPE_STATUS, 'Status', 1, ['options' => [['id' => 'done', 'label' => 'Done', 'color' => '#00c875']]]);
    $dropdown = exportColumn($board, $group, BoardColumn::TYPE_DROPDOWN, 'Stack', 2, ['options' => [['id' => 'a', 'label' => 'PHP'], ['id' => 'b', 'label' => 'React']]]);
    $people = exportColumn($board, $group, BoardColumn::TYPE_PEOPLE, 'Owner', 3);
    $date = exportColumn($board, $group, BoardColumn::TYPE_DATE, 'Due', 4);
    $number = exportColumn($board, $group, BoardColumn::TYPE_NUMBER, 'Budget', 5);
    $progress = exportColumn($board, $group, BoardColumn::TYPE_PROGRESS, 'Progress', 6);
    $timeline = exportColumn($board, $group, BoardColumn::TYPE_TIMELINE, 'Timeline', 7);
    $link = exportColumn($board, $group, BoardColumn::TYPE_LINK, 'Spec', 8);
    $checkbox = exportColumn($board, $group, BoardColumn::TYPE_CHECKBOX, 'Approved', 9);
    exportColumn($board, $group, BoardColumn::TYPE_BUTTON, 'Notify', 10);

    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Wireframes', 'position' => 0]);
    exportValue($item, $status, 'done');
    exportValue($item, $dropdown, ['a', 'b']);
    exportValue($item, $people, [(string) $user->id]);
    exportValue($item, $date, '2026-10-05');
    exportValue($item, $number, 1500);
    exportValue($item, $progress, '40');
    exportValue($item, $timeline, '2026-09-01..2026-09-30');
    exportValue($item, $link, ['url' => 'https://example.com/spec', 'text' => 'Spec doc']);
    exportValue($item, $checkbox, true);

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/exports", ['layout' => 'flat', 'include_subitems' => false])
        ->assertAccepted()
        ->assertJsonPath('data.status', BoardExportJob::STATUS_COMPLETED)
        ->assertJsonPath('data.processed_rows', 1);

    expect($response->json('data.download_url'))->toStartWith('/api/board-exports/');

    $sheet = exportedSheet(BoardExportJob::firstOrFail());
    $headers = $sheet->rangeToArray('A1:K1')[0];
    $row = array_combine(array_map(fn ($header) => (string) $header, $headers), $sheet->rangeToArray('A2:K2')[0]);

    expect($headers)->not->toContain('Notify')
        ->and($row['Group'])->toBe('Design')
        ->and($row['Name'])->toBe('Wireframes')
        ->and((string) $row['Status'])->toBe('Done')
        ->and((string) $row['Stack'])->toBe('PHP, React')
        ->and((string) $row['Owner'])->toBe('Ada Lovelace')
        ->and($row['Due'])->toBe('Oct 5, 2026')
        ->and($row['Budget'])->toBe('1500')
        ->and($row['Progress'])->toBe('40%')
        ->and((string) $row['Timeline'])->toBe('Sep 1, 2026 - Sep 30, 2026')
        ->and((string) $row['Spec'])->toBe('Spec doc')
        ->and((string) $row['Approved'])->toBe('Yes');

    expect($sheet->getCell('C2')->getStyle()->getFill()->getStartColor()->getRGB())->toBe('00C875')
        ->and($sheet->getCell('J2')->getHyperlink()->getUrl())->toBe('https://example.com/spec');
});

test('the grouped layout writes a block per group with subitems under their item', function () {
    [$board, $first_group, $second_group] = createExportBoard();
    $parent = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id, 'name' => 'Wireframes', 'position' => 0]);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id, 'parent_id' => $parent->id, 'name' => 'Mobile', 'position' => 0]);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $second_group->id, 'name' => 'Backend', 'position' => 0]);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/exports", ['layout' => 'grouped'])
        ->assertAccepted()
        ->assertJsonPath('data.total_rows', 3);

    $sheet = exportedSheet(BoardExportJob::firstOrFail());
    $values = firstColumnValues($sheet);
    $subitem_row = null;
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        if ((string) $sheet->getCell("B{$row}")->getValue() === 'Mobile') {
            $subitem_row = $row;
        }
    }

    expect($values)->toContain('Launch Plan', 'Design', 'Wireframes', 'Build', 'Backend')
        ->and($subitem_row)->not->toBeNull()
        ->and((string) $sheet->getCell("A{$subitem_row}")->getValue())->toBe('')
        ->and($sheet->getRowDimension($subitem_row)->getOutlineLevel())->toBe(1);
});

test('exporting a group only includes the items of that group', function () {
    [$board, $first_group, $second_group] = createExportBoard();
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id, 'name' => 'Wireframes']);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $second_group->id, 'name' => 'Backend']);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/exports", ['group_id' => $first_group->id])
        ->assertAccepted()
        ->assertJsonPath('data.file_name', 'launch_plan_design_'.now()->format('Y-m-d').'.xlsx');

    $values = firstColumnValues(exportedSheet(BoardExportJob::firstOrFail()));

    expect($values)->toContain('Wireframes')->not->toContain('Backend');
});

test('exporting a group of another board or an archived group is not found', function () {
    [$board, $first_group] = createExportBoard();
    [, $other_group] = createExportBoard();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/exports", ['group_id' => $other_group->id])
        ->assertNotFound();

    $first_group->update(['is_archived' => true, 'archived_at' => now()]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/exports", ['group_id' => $first_group->id])
        ->assertNotFound();
});

test('the summary counts what the export would contain', function () {
    [$board, $first_group] = createExportBoard();
    $parent = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id]);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id, 'parent_id' => $parent->id]);
    exportColumn($board, $first_group, BoardColumn::TYPE_TEXT, 'Notes', 1);

    $this->actingAs(User::factory()->create(), 'api')
        ->getJson("/api/boards/{$board->id}/exports/summary")
        ->assertOk()
        ->assertJsonPath('data.groups', 2)
        ->assertJsonPath('data.items', 1)
        ->assertJsonPath('data.subitems', 1)
        ->assertJsonPath('data.columns', 1);
});

test('the signed link downloads the file and a tampered or expired link does not', function () {
    [$board, $first_group] = createExportBoard();
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id]);

    $download_url = $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/exports")
        ->json('data.download_url');

    $this->get($download_url)
        ->assertOk()
        ->assertDownload(BoardExportJob::firstOrFail()->file_name);

    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $download_url))->assertForbidden();

    BoardExportJob::query()->update(['expires_at' => now()->subMinute()]);
    $this->get($download_url)->assertStatus(410);
});

test('another person cannot read or cancel an export', function () {
    [$board, $first_group] = createExportBoard();
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id]);

    $export_id = $this->actingAs(User::factory()->create(), 'api')
        ->postJson("/api/boards/{$board->id}/exports")
        ->json('data.id');

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'api')->getJson("/api/boards/{$board->id}/exports/{$export_id}")->assertNotFound();
    $this->actingAs($stranger, 'api')->postJson("/api/boards/{$board->id}/exports/{$export_id}/cancel")->assertNotFound();
});

test('a queued export can be cancelled before a worker picks it up', function () {
    [$board] = createExportBoard();
    $user = User::factory()->create();
    $export_job = BoardExportJob::create([
        'board_id' => $board->id,
        'board_view_id' => $board->views()->value('id'),
        'user_id' => $user->id,
        'status' => BoardExportJob::STATUS_QUEUED,
        'options' => ['layout' => 'grouped'],
        'file_name' => 'launch_plan.xlsx',
    ]);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/exports/{$export_job->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', BoardExportJob::STATUS_CANCELLED);
});

test('expired export files are pruned', function () {
    [$board] = createExportBoard();
    Storage::disk('local')->put('board-exports/old.xlsx', 'x');
    BoardExportJob::create([
        'board_id' => $board->id,
        'board_view_id' => $board->views()->value('id'),
        'user_id' => User::factory()->create()->id,
        'status' => BoardExportJob::STATUS_COMPLETED,
        'options' => [],
        'file_name' => 'old.xlsx',
        'disk' => 'local',
        'file_path' => 'board-exports/old.xlsx',
        'expires_at' => now()->subHour(),
    ]);

    $this->artisan('board-exports:prune')->assertSuccessful();

    Storage::disk('local')->assertMissing('board-exports/old.xlsx');
    expect(BoardExportJob::first()->file_path)->toBeNull();
});
