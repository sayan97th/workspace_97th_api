<?php

use App\Exports\BoardItemsExport;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Covers the group "..." menu's "Export to Excel": `GET /api/boards/{item}/export?group_id=`
 * only exports that group's items and names the file after the group.
 */
function createGroupExportBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Launch Plan',
    ]);
    $first_group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Design', 'position' => 0]);
    $second_group = BoardGroup::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $first_group->board_view_id,
        'name' => 'Build',
        'position' => 1,
    ]);

    return [$board, $first_group, $second_group];
}

test('exporting a group only includes the items of that group', function () {
    Excel::fake();
    [$board, $first_group, $second_group] = createGroupExportBoard();
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $first_group->id, 'name' => 'Wireframes']);
    BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $second_group->id, 'name' => 'Backend']);

    $this->actingAs(User::factory()->create(), 'api')
        ->get("/api/boards/{$board->id}/export?group_id={$first_group->id}")
        ->assertOk();

    Excel::assertDownloaded('launch_plan_design_export.xlsx', function ($export) {
        $titles = collect($export->array())->pluck(0)->all();

        return $export->title() === 'Design' && in_array('Wireframes', $titles, true) && ! in_array('Backend', $titles, true);
    });
});

test('exporting a group of another board is not found', function () {
    Excel::fake();
    [$board] = createGroupExportBoard();
    [, $other_group] = createGroupExportBoard();

    $this->actingAs(User::factory()->create(), 'api')
        ->get("/api/boards/{$board->id}/export?group_id={$other_group->id}")
        ->assertNotFound();
});

test('exporting an archived group is not found', function () {
    Excel::fake();
    [$board, $first_group] = createGroupExportBoard();
    $first_group->update(['is_archived' => true, 'archived_at' => now()]);

    $this->actingAs(User::factory()->create(), 'api')
        ->get("/api/boards/{$board->id}/export?group_id={$first_group->id}")
        ->assertNotFound();
});

test('structured cell values are exported as readable text', function () {
    $export = new BoardItemsExport('Board', collect(), collect(), collect());
    $stringify = (new ReflectionClass($export))->getMethod('stringify');

    expect($stringify->invoke($export, ['from' => '2026-10-01', 'to' => '2026-10-05']))->toBe('2026-10-01 - 2026-10-05')
        ->and($stringify->invoke($export, [['name' => 'brief.pdf', 'url' => 'https://x.test/a'], ['name' => 'logo.png']]))->toBe('brief.pdf, logo.png')
        ->and($stringify->invoke($export, ['url' => 'https://97thfloor.com', 'text' => 'Site']))->toBe('Site')
        ->and($stringify->invoke($export, ['a', 'b']))->toBe('a, b');
});
