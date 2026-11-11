<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/** An item on a fresh board in a workspace the given members belong to. */
function createFeedReactionItem(User ...$members): BoardItem
{
    $workspace = Workspace::factory()->create();
    foreach ($members as $member) {
        $workspace->users()->attach($member->id, ['role' => 'member']);
    }

    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

function postFeedReactionComment(BoardItem $item, User $author, User $viewer): BoardItemComment
{
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Ready for review']);
    $comment->mentions()->create(['user_id' => $viewer->id]);

    return $comment;
}

test('liking a feed card returns the like count, the viewer flag and who liked', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $comment = postFeedReactionComment(createFeedReactionItem($viewer, $author), $author, $viewer);

    $liked = $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$comment->id}/like")->assertOk();

    expect($liked->json('data.like_count'))->toBe(1)
        ->and($liked->json('data.liked_by_me'))->toBeTrue()
        ->and($liked->json('data.likers.0.id'))->toBe($viewer->id);

    $unliked = $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$comment->id}/like")->assertOk();

    expect($unliked->json('data.like_count'))->toBe(0)
        ->and($unliked->json('data.liked_by_me'))->toBeFalse();
});

test('the feed list carries likes and reactions on every card', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $comment = postFeedReactionComment(createFeedReactionItem($viewer, $author), $author, $viewer);
    $comment->likes()->create(['user_id' => $author->id]);
    $comment->reactions()->create(['user_id' => $author->id, 'emoji' => '🎉']);

    $response = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates')->assertOk();

    expect($response->json('data.0.like_count'))->toBe(1)
        ->and($response->json('data.0.liked_by_me'))->toBeFalse()
        ->and($response->json('data.0.reactions.0.emoji'))->toBe('🎉')
        ->and($response->json('data.0.reactions.0.reactors.0.id'))->toBe($author->id);
});

test('reacting to a feed card toggles the emoji and notifies the author', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $comment = postFeedReactionComment(createFeedReactionItem($viewer, $author), $author, $viewer);

    $reacted = $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$comment->id}/reactions", ['emoji' => '🎉'])
        ->assertOk();

    expect($reacted->json('data.reactions.0.emoji'))->toBe('🎉')
        ->and($reacted->json('data.reactions.0.count'))->toBe(1)
        ->and($reacted->json('data.reactions.0.reacted_by_me'))->toBeTrue()
        ->and($reacted->json('data.reactions.0.reactor_names.0'))->toBe('You');

    $this->assertDatabaseHas('notifications', ['user_id' => $author->id, 'type' => 'reactions']);

    $cleared = $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$comment->id}/reactions", ['emoji' => '🎉'])
        ->assertOk();

    expect($cleared->json('data.reactions'))->toBeEmpty();
});

test('a feed reaction needs a real emoji', function () {
    $viewer = User::factory()->create();
    $comment = postFeedReactionComment(createFeedReactionItem($viewer), $viewer, $viewer);

    $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$comment->id}/reactions", ['emoji' => 'not an emoji'])
        ->assertUnprocessable();
});

test('people outside the workspace cannot like or react to a feed card', function () {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $comment = postFeedReactionComment(createFeedReactionItem($member), $member, $member);

    $this->actingAs($outsider, 'api')->postJson("/api/feed/updates/ic-{$comment->id}/like")->assertForbidden();
    $this->actingAs($outsider, 'api')
        ->postJson("/api/feed/updates/ic-{$comment->id}/reactions", ['emoji' => '🎉'])
        ->assertForbidden();
});
