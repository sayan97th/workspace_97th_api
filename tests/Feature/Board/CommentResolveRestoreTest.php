<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\CommentThreadActionsService;

/**
 * @return array{0: BoardItem, 1: User}
 */
function createResolveTestItem(string $role = 'member'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user->id, ['role' => $role]);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]), $user];
}

test('an item update can be resolved and reopened, and the resource says who resolved it', function () {
    [$item, $user] = createResolveTestItem();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Please review']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/resolve";

    $this->actingAs($user, 'api')->postJson($url)
        ->assertOk()
        ->assertJsonPath('comment.is_resolved', true)
        ->assertJsonPath('comment.resolved_by.id', $user->id);

    $this->assertDatabaseHas('board_item_comments', ['id' => $comment->id, 'resolved_by_id' => $user->id]);

    $this->actingAs($user, 'api')->postJson($url)
        ->assertOk()
        ->assertJsonPath('comment.is_resolved', false)
        ->assertJsonPath('comment.resolved_by', null);

    expect($comment->fresh()->resolved_at)->toBeNull();
});

test('a reply cannot be resolved and a viewer cannot resolve a thread', function () {
    [$item, $user] = createResolveTestItem();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Update']);
    $reply = $item->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'Reply']);

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$reply->id}/resolve")
        ->assertUnprocessable();

    [$viewer_item, $viewer] = createResolveTestItem('viewer');
    $viewer_comment = $viewer_item->comments()->create(['user_id' => $viewer->id, 'body' => 'Update']);

    $this->actingAs($viewer, 'api')
        ->postJson("/api/boards/{$viewer_item->board_id}/items/{$viewer_item->id}/comments/{$viewer_comment->id}/resolve")
        ->assertForbidden();
});

test('a board update can be resolved and reopened', function () {
    [$item, $user] = createResolveTestItem();
    $comment = BoardComment::create(['board_id' => $item->board_id, 'user_id' => $user->id, 'body' => 'Board wide']);
    $url = "/api/boards/{$item->board_id}/comments/{$comment->id}/resolve";

    $this->actingAs($user, 'api')->postJson($url)->assertOk()->assertJsonPath('comment.is_resolved', true);
    $this->actingAs($user, 'api')->postJson($url)->assertOk()->assertJsonPath('comment.is_resolved', false);
});

test('a deleted item comment can be restored by its author inside the undo window', function () {
    [$item, $user] = createResolveTestItem();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Oops']);
    $reply = $item->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'Kept reply']);
    $base = "/api/boards/{$item->board_id}/items/{$item->id}/comments";

    $this->actingAs($user, 'api')->deleteJson("{$base}/{$comment->id}")->assertOk();
    $this->getJson($base)->assertJsonCount(0, 'data');

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'api')->postJson("{$base}/{$comment->id}/restore")->assertForbidden();

    $this->actingAs($user, 'api')->postJson("{$base}/{$comment->id}/restore")
        ->assertOk()
        ->assertJsonPath('comment.id', $comment->id)
        ->assertJsonPath('comment.replies.0.id', $reply->id);

    $this->assertNotSoftDeleted('board_item_comments', ['id' => $comment->id]);
});

test('a comment cannot be restored after the undo window or twice', function () {
    [$item, $user] = createResolveTestItem();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Gone']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/restore";

    // Not deleted yet, nothing to restore.
    $this->actingAs($user, 'api')->postJson($url)->assertNotFound();

    $comment->delete();
    $this->travel(CommentThreadActionsService::UNDO_WINDOW_DAYS + 1)->days();

    $this->actingAs($user, 'api')->postJson($url)->assertStatus(410);
});

test('a reply cannot be restored while its update stays deleted', function () {
    [$item, $user] = createResolveTestItem();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Update']);
    $reply = $item->comments()->create(['user_id' => $user->id, 'parent_id' => $comment->id, 'body' => 'Reply']);

    $reply->delete();
    $comment->delete();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$reply->id}/restore")
        ->assertStatus(409);
});

test('a deleted board comment can be restored by its author', function () {
    [$item, $user] = createResolveTestItem();
    $comment = BoardComment::create(['board_id' => $item->board_id, 'user_id' => $user->id, 'body' => 'Oops']);
    $base = "/api/boards/{$item->board_id}/comments";

    $this->actingAs($user, 'api')->deleteJson("{$base}/{$comment->id}")->assertOk();
    $this->actingAs($user, 'api')->postJson("{$base}/{$comment->id}/restore")->assertOk()->assertJsonPath('comment.id', $comment->id);

    $this->assertNotSoftDeleted('board_comments', ['id' => $comment->id]);
});

test('reactions list who reacted and when', function () {
    [$item, $user] = createResolveTestItem();
    $other = User::factory()->create();
    $comment = $item->comments()->create(['user_id' => $user->id, 'body' => 'Nice']);
    $url = "/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/reactions";

    $this->actingAs($user, 'api')->postJson($url, ['emoji' => '👍'])->assertOk();
    $response = $this->actingAs($other, 'api')->postJson($url, ['emoji' => '👍'])->assertOk();

    $response->assertJsonPath('comment.reactions.0.count', 2)
        ->assertJsonCount(2, 'comment.reactions.0.reactors')
        ->assertJsonPath('comment.reactions.0.reactors.0.id', $user->id)
        ->assertJsonPath('comment.reactions.0.reactors.1.full_name', $other->full_name);
});
