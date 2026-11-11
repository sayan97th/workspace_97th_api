<?php

use App\Models\BoardGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createLikersBoard(): array
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $item = $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);

    $author = User::factory()->create();
    $liker = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $workspace->users()->attach([$author->id => ['role' => 'member'], $liker->id => ['role' => 'member']]);

    return [$board, $item, $author, $liker];
}

test('an item comment lists who liked it', function () {
    [$board, $item, $author, $liker] = createLikersBoard();

    $comment_id = $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments", ['body' => 'Hello'])
        ->assertCreated()
        ->json('comment.id');

    $this->actingAs($liker, 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments/{$comment_id}/like")
        ->assertOk()
        ->assertJsonPath('comment.like_count', 1)
        ->assertJsonPath('comment.liked_by_me', true)
        ->assertJsonPath('comment.likers.0.id', $liker->id)
        ->assertJsonPath('comment.likers.0.full_name', 'Ada Lovelace');

    $this->actingAs($author, 'api')
        ->getJson("/api/boards/{$board->id}/items/{$item->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.likers.0.id', $liker->id)
        ->assertJsonStructure(['data' => [['likers' => [['id', 'full_name', 'profile_photo_url', 'is_deactivated', 'liked_at']]]]]);

    $this->actingAs($liker, 'api')
        ->postJson("/api/boards/{$board->id}/items/{$item->id}/comments/{$comment_id}/like")
        ->assertOk()
        ->assertJsonPath('comment.like_count', 0)
        ->assertJsonCount(0, 'comment.likers');
});

test('a board discussion update lists who liked it', function () {
    [$board, , $author, $liker] = createLikersBoard();

    $comment_id = $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$board->id}/comments", ['body' => 'Hello'])
        ->assertCreated()
        ->json('comment.id');

    $this->actingAs($liker, 'api')
        ->postJson("/api/boards/{$board->id}/comments/{$comment_id}/like")
        ->assertOk()
        ->assertJsonPath('comment.likers.0.id', $liker->id)
        ->assertJsonPath('comment.likers.0.full_name', 'Ada Lovelace');
});
