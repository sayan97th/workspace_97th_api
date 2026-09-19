<?php

use App\Exports\BoardItemUpdatesExport;
use App\Models\BoardGroup;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Maatwebsite\Excel\Facades\Excel;

function createUpdatesExportItem(): array
{
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board, $board->items()->create(['group_id' => $group->id, 'name' => 'Launch: v2?', 'position' => 0])];
}

test('an item updates export downloads a workbook with each update followed by its replies', function () {
    Excel::fake();
    [$board, $item] = createUpdatesExportItem();
    $user = User::factory()->create();

    $update = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $user->id, 'body' => 'This is **bold** with a [link](https://example.com)']);
    BoardItemComment::create(['item_id' => $item->id, 'parent_id' => $update->id, 'user_id' => $user->id, 'body' => 'A reply']);

    $this->actingAs($user, 'api')
        ->get("/api/boards/{$board->id}/items/{$item->id}/updates/export")
        ->assertOk();

    Excel::assertDownloaded('launch_v2_updates.xlsx', function (BoardItemUpdatesExport $export) use ($user) {
        $rows = $export->array();

        return $export->title() === 'Launch  v2'
            && count($rows) === 2
            && $rows[0][0] === 'Update'
            && $rows[0][1] === $user->full_name
            && $rows[0][7] === 'This is bold with a link (https://example.com)'
            && $rows[1][0] === 'Reply'
            && $rows[1][7] === 'A reply';
    });
});

test('an item updates export for an item of another board is not found', function () {
    [, $item] = createUpdatesExportItem();
    [$other_board] = createUpdatesExportItem();
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->get("/api/boards/{$other_board->id}/items/{$item->id}/updates/export")
        ->assertNotFound();
});
