<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ScheduledCommentService;

/**
 * @return array{0: BoardItem, 1: Workspace}
 */
function createCollaborationItem(bool $with_columns = false): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    if ($with_columns) {
        BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_PEOPLE, 'position' => 1]);
        BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $group->board_view_id, 'type' => BoardColumn::TYPE_DATE, 'position' => 2]);
    }

    return [$board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]), $workspace];
}

test('a scheduled comment stays hidden and sends nothing until it is published', function () {
    [$item, $workspace] = createCollaborationItem();
    $author = User::factory()->create();
    $mentioned = User::factory()->create();
    $workspace->users()->attach([$author->id => ['role' => 'member'], $mentioned->id => ['role' => 'member']]);

    $response = $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Later @'.$mentioned->full_name,
        'mentioned_user_ids' => [$mentioned->id],
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated();

    $comment_id = $response->json('comment.id');
    expect(Notification::where('user_id', $mentioned->id)->count())->toBe(0);

    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/scheduled")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comment_id);
    $this->actingAs($mentioned, 'api')->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/scheduled")->assertOk()->assertJsonCount(0, 'data');

    // Sending now publishes it: the mention is notified and the thread shows it.
    $this->actingAs($author, 'api')->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment_id}/schedule", ['scheduled_at' => null])
        ->assertOk()
        ->assertJsonPath('comment.scheduled_at', null);

    expect(Notification::where('user_id', $mentioned->id)->where('type', Notification::TYPE_MENTIONED)->count())->toBe(1);
    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/items/{$item->id}/comments")->assertOk()->assertJsonCount(1, 'data');
});

test('a scheduled comment can be rescheduled by its author only and goes live when due', function () {
    [$item, $workspace] = createCollaborationItem();
    $author = User::factory()->create();
    $other = User::factory()->create();
    $workspace->users()->attach([$author->id => ['role' => 'member'], $other->id => ['role' => 'member']]);

    $comment_id = $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Later',
        'notified_user_ids' => [$other->id],
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated()->json('comment.id');

    $new_time = now()->addDay()->startOfMinute();
    $this->actingAs($other, 'api')->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment_id}/schedule", ['scheduled_at' => $new_time->toIso8601String()])->assertForbidden();
    $this->actingAs($author, 'api')->patchJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment_id}/schedule", ['scheduled_at' => $new_time->toIso8601String()])->assertOk();

    expect(BoardItemComment::find($comment_id)->scheduled_at->equalTo($new_time))->toBeTrue();

    // Time passes: the command's service publishes it and the "Notify" list is only told now.
    BoardItemComment::whereKey($comment_id)->update(['scheduled_at' => now()->subMinute()]);
    expect(app(ScheduledCommentService::class)->publishDue())->toBe(1)
        ->and(BoardItemComment::find($comment_id)->scheduled_at)->toBeNull()
        ->and(Notification::where('user_id', $other->id)->where('type', Notification::TYPE_NOTIFIED)->count())->toBe(1);
});

test('a comment cannot be scheduled in the past or combined with an assignment', function () {
    [$item] = createCollaborationItem(true);
    $author = User::factory()->create();
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments";

    $this->actingAs($author, 'api')->postJson($url, ['body' => 'x', 'scheduled_at' => now()->subHour()->toIso8601String()])->assertUnprocessable();
    $this->actingAs($author, 'api')->postJson($url, [
        'body' => 'x',
        'scheduled_at' => now()->addHour()->toIso8601String(),
        'assign_user_ids' => [$author->id],
    ])->assertUnprocessable();
});

test('the discussion drawer schedules and cancels an update', function () {
    [$item, $workspace] = createCollaborationItem();
    $author = User::factory()->create();
    $workspace->users()->attach($author->id, ['role' => 'member']);

    $comment_id = $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/comments", [
        'body' => 'Board note',
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated()->json('comment.id');

    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/comments")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/comments/scheduled")->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($author, 'api')->deleteJson("/api/boards/{$item->board_id}/comments/{$comment_id}")->assertOk();
    $this->actingAs($author, 'api')->getJson("/api/boards/{$item->board_id}/comments/scheduled")->assertOk()->assertJsonCount(0, 'data');
});

test('a comment can assign people and a due date to the item', function () {
    [$item, $workspace] = createCollaborationItem(true);
    $author = User::factory()->create();
    $assignee = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace->users()->attach([$author->id => ['role' => 'member'], $assignee->id => ['role' => 'member']]);

    $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Please handle this',
        'assign_user_ids' => [$assignee->id, $outsider->id],
        'assign_due_date' => '2030-01-15',
    ])->assertCreated();

    $people_column = BoardColumn::where('board_id', $item->board_id)->where('type', BoardColumn::TYPE_PEOPLE)->first();
    $date_column = BoardColumn::where('board_id', $item->board_id)->where('type', BoardColumn::TYPE_DATE)->first();

    // Only workspace members are assigned, and the assignee is told through the usual notification.
    expect($item->values()->where('column_id', $people_column->id)->first()->value)->toBe([$assignee->id])
        ->and($item->values()->where('column_id', $date_column->id)->first()->value)->toBe('2030-01-15')
        ->and(Notification::where('user_id', $assignee->id)->where('type', Notification::TYPE_ASSIGNED)->count())->toBe(1);

    $this->assertDatabaseHas('board_item_activities', ['item_id' => $item->id, 'column_type' => 'date', 'new_display' => 'Jan 15, 2030']);
    $this->assertDatabaseHas('board_item_activities', ['item_id' => $item->id, 'column_type' => 'people', 'new_display' => $assignee->full_name]);
});

test('an assignment on a board without a People column fails before the comment is posted', function () {
    [$item] = createCollaborationItem();
    $author = User::factory()->create();

    $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Please handle this',
        'assign_user_ids' => [$author->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('assign_user_ids');

    expect(BoardItemComment::count())->toBe(0);
});

test('a read-only workspace member cannot pin or assign', function () {
    [$item, $workspace] = createCollaborationItem(true);
    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer->id, ['role' => 'viewer']);
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $viewer->id, 'body' => 'Hi']);

    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/pin")->assertForbidden();
    $this->actingAs($viewer, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
        'body' => 'Assign me',
        'assign_user_ids' => [$viewer->id],
    ])->assertForbidden();

    expect($comment->fresh()->pinned)->toBeFalse();
});

test('a comment can be bookmarked and the seen list reports when each person saw it', function () {
    [$item, $workspace] = createCollaborationItem();
    $reader = User::factory()->create();
    $workspace->users()->attach($reader->id, ['role' => 'member']);
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $reader->id, 'body' => 'Hi']);
    $base = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}";

    $this->actingAs($reader, 'api')->postJson("{$base}/bookmark")->assertOk()->assertJsonPath('comment.bookmarked_by_me', true);
    $this->actingAs($reader, 'api')->getJson('/api/feed/updates?tab=bookmarked')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($reader, 'api')->postJson("{$base}/bookmark")->assertOk()->assertJsonPath('comment.bookmarked_by_me', false);

    $this->actingAs($reader, 'api')->postJson("{$base}/seen")
        ->assertOk()
        ->assertJsonPath('comment.seen_by.0.id', $reader->id)
        ->assertJsonStructure(['comment' => ['seen_by' => [['seen_at']]]]);
});
