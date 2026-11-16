<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createEditMentionsBoard(User ...$members): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    addBoardMembers($board, ...$members);

    return $board;
}

function createEditMentionsItem(User ...$members): BoardItem
{
    $board = createEditMentionsBoard(...$members);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Venue booking', 'position' => 0]);
}

function mentionNotificationCount(User $user): int
{
    return Notification::where('user_id', $user->id)->where('type', Notification::TYPE_MENTIONED)->count();
}

test('editing an item update can mention someone new, who is notified once', function () {
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createEditMentionsItem($author, $teammate);
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Venue is closed']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}";
    $payload = [
        '_method' => 'PATCH',
        'body' => '@'.$teammate->full_name.' Venue is closed',
        'mentioned_user_ids' => [$teammate->id],
    ];

    $this->actingAs($author, 'api')->post($url, $payload, ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('comment.mentioned_user_ids', [$teammate->id]);

    expect(mentionNotificationCount($teammate))->toBe(1);

    // Saving again with the same mention neither duplicates the record nor notifies twice.
    $payload['body'] .= ' tomorrow';
    $this->actingAs($author, 'api')->post($url, $payload, ['Accept' => 'application/json'])->assertOk();

    expect(mentionNotificationCount($teammate))->toBe(1);
    expect($comment->mentions()->count())->toBe(1);
});

test('editing an update ignores mentions of people outside the board', function () {
    $author = User::factory()->create();
    $outsider = User::factory()->create();
    $item = createEditMentionsItem($author);
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Hello']);

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}",
        ['_method' => 'PATCH', 'body' => '@'.$outsider->full_name.' Hello', 'mentioned_user_ids' => [$outsider->id]],
        ['Accept' => 'application/json']
    )->assertOk()->assertJsonPath('comment.mentioned_user_ids', []);

    expect(mentionNotificationCount($outsider))->toBe(0);
});

test('editing a board discussion update can mention someone new', function () {
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    $board = createEditMentionsBoard($author, $teammate);
    $comment = BoardComment::create(['board_id' => $board->id, 'user_id' => $author->id, 'body' => 'Kickoff notes']);

    $this->actingAs($author, 'api')->post(
        "/api/boards/{$board->id}/comments/{$comment->id}",
        ['_method' => 'PATCH', 'body' => '@'.$teammate->full_name.' Kickoff notes', 'mentioned_user_ids' => [$teammate->id]],
        ['Accept' => 'application/json']
    )->assertOk()->assertJsonPath('comment.mentioned_user_ids', [$teammate->id]);

    expect(mentionNotificationCount($teammate))->toBe(1);
});

test('editing an update from the inbox can mention someone new', function () {
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createEditMentionsItem($author, $teammate);
    $comment = $item->comments()->create(['user_id' => $author->id, 'body' => 'Draft agenda']);

    $this->actingAs($author, 'api')->patchJson("/api/feed/updates/ic-{$comment->id}", [
        'body' => '@'.$teammate->full_name.' Draft agenda',
        'mentioned_user_ids' => [$teammate->id],
    ])->assertOk();

    expect(mentionNotificationCount($teammate))->toBe(1);
    expect($comment->mentions()->pluck('user_id')->all())->toBe([$teammate->id]);
});
