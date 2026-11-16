<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/**
 * Covers `has_viewer_mention` on the items index, which turns a row's chat
 * bubble blue (instead of gray) while the viewer is tagged in its updates,
 * like monday.com.
 */
function createViewerMentionBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board, $group];
}

function postViewerMentionComment(BoardItem $item, User $author, array $mentioned_ids = [], array $attributes = []): BoardItemComment
{
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Hello'] + $attributes);
    foreach ($mentioned_ids as $user_id) {
        $comment->mentions()->create(['user_id' => $user_id]);
    }

    return $comment;
}

function viewerMentionRows($test, WorkspaceNavigationItem $board, User $viewer): array
{
    return collect($test->actingAs($viewer, 'api')->getJson("/api/boards/{$board->id}/items")->assertOk()->json('data'))
        ->keyBy('id')
        ->all();
}

test('only items where the viewer is mentioned are flagged', function () {
    [$board, $group] = createViewerMentionBoard();
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();

    $tagged = $board->items()->create(['group_id' => $group->id, 'name' => 'SafeStreets', 'position' => 0]);
    $other = $board->items()->create(['group_id' => $group->id, 'name' => 'Unitree', 'position' => 1]);
    postViewerMentionComment($tagged, $teammate, [$viewer->id]);
    postViewerMentionComment($other, $teammate, [$teammate->id]);
    postViewerMentionComment($other, $teammate);

    $rows = viewerMentionRows($this, $board, $viewer);

    expect($rows[$tagged->id]['has_viewer_mention'])->toBeTrue()
        ->and($rows[$tagged->id]['comment_count'])->toBe(1)
        ->and($rows[$other->id]['has_viewer_mention'])->toBeFalse()
        ->and($rows[$other->id]['comment_count'])->toBe(2);

    // The flag is per viewer: the teammate is tagged on the other row instead.
    $teammate_rows = viewerMentionRows($this, $board, $teammate);
    expect($teammate_rows[$tagged->id]['has_viewer_mention'])->toBeFalse()
        ->and($teammate_rows[$other->id]['has_viewer_mention'])->toBeTrue();
});

test('a mention in a reply flags the item', function () {
    [$board, $group] = createViewerMentionBoard();
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();

    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Azalea Health', 'position' => 0]);
    $update = postViewerMentionComment($item, $teammate);
    postViewerMentionComment($item, $teammate, [$viewer->id], ['parent_id' => $update->id]);

    expect(viewerMentionRows($this, $board, $viewer)[$item->id]['has_viewer_mention'])->toBeTrue();
});

test('subitems carry their own mention flag', function () {
    [$board, $group] = createViewerMentionBoard();
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();

    $parent = $board->items()->create(['group_id' => $group->id, 'name' => 'Momentive', 'position' => 0]);
    $sub = $board->items()->create(['group_id' => $group->id, 'parent_id' => $parent->id, 'name' => 'Kickoff', 'position' => 0]);
    postViewerMentionComment($sub, $teammate, [$viewer->id]);

    $row = viewerMentionRows($this, $board, $viewer)[$parent->id];

    expect($row['has_viewer_mention'])->toBeFalse()
        ->and($row['children'][0]['has_viewer_mention'])->toBeTrue();
});

test('scheduled and deleted updates do not flag the item', function () {
    [$board, $group] = createViewerMentionBoard();
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();

    $scheduled = $board->items()->create(['group_id' => $group->id, 'name' => 'Zenni Optical', 'position' => 0]);
    $deleted = $board->items()->create(['group_id' => $group->id, 'name' => 'RCG Law Group', 'position' => 1]);
    postViewerMentionComment($scheduled, $teammate, [$viewer->id], ['scheduled_at' => now()->addDay()]);
    postViewerMentionComment($deleted, $teammate, [$viewer->id])->delete();

    $rows = viewerMentionRows($this, $board, $viewer);

    expect($rows[$scheduled->id]['has_viewer_mention'])->toBeFalse()
        ->and($rows[$deleted->id]['has_viewer_mention'])->toBeFalse();
});
