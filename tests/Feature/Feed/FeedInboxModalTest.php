<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/** An item on a fresh board whose workspace holds every given member. */
function createInboxItem(User ...$members): BoardItem
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

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Jelly Fish Season', 'position' => 0]);
}

function postInboxComment(BoardItem $item, User $author, string $body, ?BoardItemComment $parent = null): BoardItemComment
{
    return BoardItemComment::create([
        'item_id' => $item->id,
        'user_id' => $author->id,
        'body' => $body,
        'parent_id' => $parent?->id,
    ]);
}

test('a hidden board leaves the inbox, the board list and the unread count until it is shown again', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($viewer, $teammate);
    $root = postInboxComment($item, $viewer, 'Outgoing email');
    postInboxComment($item, $teammate, 'Yay!', $root);

    $this->actingAs($viewer, 'api');
    expect($this->getJson('/api/feed/updates')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/feed/unread-count')->json('data.unread_count'))->toBe(1);

    $this->postJson('/api/feed/hidden-boards', ['board_id' => $item->board_id])->assertCreated();

    expect($this->getJson('/api/feed/updates')->json('data'))->toHaveCount(0)
        ->and($this->getJson('/api/feed/updates?tab=account')->json('data'))->toHaveCount(0)
        ->and(collect($this->getJson('/api/feed/boards')->json('data'))->pluck('id')->all())->toBe(['all-boards'])
        ->and($this->getJson('/api/feed/unread-count')->json('data.unread_count'))->toBe(0)
        ->and($this->getJson('/api/feed/hidden-boards')->json('data.0.id'))->toBe((string) $item->board_id);

    $this->deleteJson("/api/feed/hidden-boards/{$item->board_id}")->assertOk();

    expect($this->getJson('/api/feed/updates')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/feed/hidden-boards')->json('data'))->toHaveCount(0);
});

test('a board outside the viewer workspaces cannot be hidden', function () {
    $viewer = User::factory()->create();
    $item = createInboxItem(User::factory()->create());

    $this->actingAs($viewer, 'api')->postJson('/api/feed/hidden-boards', ['board_id' => $item->board_id])->assertForbidden();
});

test('only the author can edit an update from the inbox and the edit keeps a revision', function () {
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($author, $teammate);
    $comment = postInboxComment($item, $author, 'First draft');

    $this->actingAs($teammate, 'api')->patchJson("/api/feed/updates/ic-{$comment->id}", ['body' => 'Hijacked'])->assertForbidden();

    $response = $this->actingAs($author, 'api')->patchJson("/api/feed/updates/ic-{$comment->id}", ['body' => 'Final copy'])->assertOk();

    expect($response->json('data.body'))->toBe('Final copy')
        ->and($response->json('data.edited_at'))->not->toBeNull()
        ->and($comment->revisions()->count())->toBe(1);
});

test('the author can delete an update for everyone and undo it', function () {
    $author = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($author, $teammate);
    $comment = postInboxComment($item, $author, 'Delete me');

    $this->actingAs($teammate, 'api')->deleteJson("/api/feed/updates/ic-{$comment->id}")->assertForbidden();

    $this->actingAs($author, 'api')->deleteJson("/api/feed/updates/ic-{$comment->id}")->assertOk();
    expect(BoardItemComment::find($comment->id))->toBeNull();

    $this->postJson("/api/feed/updates/ic-{$comment->id}/restore")->assertOk()->assertJsonPath('data.body', 'Delete me');
    expect(BoardItemComment::find($comment->id))->not->toBeNull();
});

test('marking a thread unread brings its replies back as unread too', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($viewer, $teammate);
    $root = postInboxComment($item, $teammate, 'Update');
    postInboxComment($item, $teammate, 'Reply', $root);

    $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true])->assertOk();

    $response = $this->deleteJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true])->assertOk();

    expect($response->json('data.is_unread'))->toBeTrue()
        ->and($response->json('data.replies.0.is_unread'))->toBeTrue();
});

test('undoing the last close only reopens the rows that close read', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($viewer, $teammate);
    $root = postInboxComment($item, $teammate, 'Update');
    $old_reply = postInboxComment($item, $teammate, 'Read before', $root);
    $new_reply = postInboxComment($item, $teammate, 'Still unread', $root);

    // The viewer had already read one reply before closing the thread.
    $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$old_reply->id}/seen")->assertOk();

    $closed = $this->postJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true])->assertOk();
    $closed_ids = $closed->json('meta.closed_comment_ids');

    expect($closed_ids)->toEqualCanonicalizing([$root->id, $new_reply->id]);

    $response = $this->deleteJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true, 'comment_ids' => $closed_ids])->assertOk();

    $replies = collect($response->json('data.replies'))->keyBy('id');
    expect($response->json('data.is_unread'))->toBeTrue()
        ->and($replies["ic-{$old_reply->id}"]['is_unread'])->toBeFalse()
        ->and($replies["ic-{$new_reply->id}"]['is_unread'])->toBeTrue();
});

test('undoing a close ignores comment ids from other threads', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createInboxItem($viewer, $teammate);
    $root = postInboxComment($item, $teammate, 'Update');
    $other = postInboxComment($item, $teammate, 'Other update');

    $this->actingAs($viewer, 'api')->postJson("/api/feed/updates/ic-{$other->id}/seen", ['thread' => true])->assertOk();
    $this->postJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true])->assertOk();

    $this->deleteJson("/api/feed/updates/ic-{$root->id}/seen", ['thread' => true, 'comment_ids' => [$root->id, $other->id]])->assertOk();

    expect($other->views()->where('user_id', $viewer->id)->exists())->toBeTrue()
        ->and($root->views()->where('user_id', $viewer->id)->exists())->toBeFalse();
});
