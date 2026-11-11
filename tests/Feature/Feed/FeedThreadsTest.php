<?php

use App\Events\NewFeedUpdate;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/** An item on a fresh board whose workspace holds every given member. */
function createFeedThreadItem(User ...$members): BoardItem
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

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Strategy deck', 'position' => 0]);
}

function postFeedThreadComment(BoardItem $item, User $author, string $body, ?BoardItemComment $parent = null, int $minutes_ago = 0): BoardItemComment
{
    $comment = new BoardItemComment([
        'item_id' => $item->id,
        'user_id' => $author->id,
        'body' => $body,
        'parent_id' => $parent?->id,
    ]);
    $comment->created_at = now()->subMinutes($minutes_ago);
    $comment->save();

    return $comment;
}

test('replies show inline under their update instead of as cards of their own', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createFeedThreadItem($viewer, $teammate);

    $root = postFeedThreadComment($item, $viewer, 'Met today and devised a strategy', minutes_ago: 30);
    postFeedThreadComment($item, $teammate, 'Will take a look today', $root, minutes_ago: 20);
    postFeedThreadComment($item, $viewer, 'Thanks!', $root, minutes_ago: 10);

    $response = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe("ic-{$root->id}")
        ->and($response->json('data.0.thread_id'))->toBe("ic-{$root->id}")
        ->and(collect($response->json('data.0.replies'))->pluck('body')->all())->toBe(['Will take a look today', 'Thanks!'])
        ->and($response->json('data.0.replies.0.thread_id'))->toBe("ic-{$root->id}")
        ->and($response->json('data.0.replies.0.is_unread'))->toBeTrue()
        ->and($response->json('data.0.replies.1.is_unread'))->toBeFalse();
});

test('a reply by the viewer brings the whole thread in once', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createFeedThreadItem($viewer, $teammate);

    // The viewer neither wrote nor was mentioned in the update, only replied to it.
    $root = postFeedThreadComment($item, $teammate, 'Here is the deck', minutes_ago: 30);
    postFeedThreadComment($item, $viewer, 'Looks good', $root, minutes_ago: 5);
    postFeedThreadComment($item, $viewer, 'One more note', $root, minutes_ago: 1);

    $response = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.body'))->toBe('Here is the deck')
        ->and($response->json('data.0.replies'))->toHaveCount(2);
});

test('marking a thread seen also marks its replies seen', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createFeedThreadItem($viewer, $teammate);
    $root = postFeedThreadComment($item, $teammate, 'Update', minutes_ago: 3);
    $reply = postFeedThreadComment($item, $teammate, 'Reply', $root, minutes_ago: 1);

    $response = $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true])->assertOk();

    expect($response->json('data.is_unread'))->toBeFalse()
        ->and($response->json('data.replies.0.is_unread'))->toBeFalse()
        ->and($reply->views()->where('user_id', $viewer->id)->exists())->toBeTrue();
});

test('liking a reply answers with the reply, liking an update with its whole thread', function () {
    $viewer = User::factory()->create();
    $item = createFeedThreadItem($viewer);
    $root = postFeedThreadComment($item, $viewer, 'Update', minutes_ago: 3);
    $reply = postFeedThreadComment($item, $viewer, 'Reply', $root, minutes_ago: 1);

    $on_reply = $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$reply->id}/like")->assertOk();
    $on_root = $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$root->id}/like")->assertOk();

    expect($on_reply->json('data.id'))->toBe("ic-{$reply->id}")
        ->and($on_reply->json('data.liked_by_me'))->toBeTrue()
        ->and($on_root->json('data.replies.0.liked_by_me'))->toBeTrue()
        ->and($on_root->json('data.liked_by_me'))->toBeTrue();
});

test('a live reply carries its whole thread', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createFeedThreadItem($viewer, $teammate);
    $root = postFeedThreadComment($item, $viewer, 'Update', minutes_ago: 3);
    $reply = postFeedThreadComment($item, $teammate, 'Reply', $root, minutes_ago: 1);

    $payload = (new NewFeedUpdate($reply, $viewer))->broadcastWith();

    expect($payload['id'])->toBe("ic-{$reply->id}")
        ->and($payload['thread']['id'])->toBe("ic-{$root->id}")
        ->and(collect($payload['thread']['replies'])->pluck('id')->all())->toBe(["ic-{$reply->id}"]);
});
