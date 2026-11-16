<?php

use App\Events\BoardItemCommentsChanged;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Board\ScheduledCommentService;
use Illuminate\Support\Facades\Event;

/**
 * Covers the live updates counter of a board row (the chat bubble next to the item name):
 * `BoardItemCommentsChanged` on `board-comments.{board_id}`, the `item_comments` summary on the
 * comment endpoints and the `comment-counts` catch up endpoint.
 */
function createLiveCountItem(): BoardItem
{
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

test('posting an update tells the whole board the new count', function () {
    Event::fake([BoardItemCommentsChanged::class]);
    $item = createLiveCountItem();
    $author = User::factory()->create();
    $mentioned = User::factory()->create();
    addBoardMembers($item->board, $mentioned);
    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Earlier']);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
            'body' => 'Hello',
            'mentioned_user_ids' => [$mentioned->id],
        ])
        ->assertCreated()
        ->assertJsonPath('item_comments.item_id', $item->id)
        ->assertJsonPath('item_comments.comment_count', 2)
        ->assertJsonPath('item_comments.mentioned_user_ids', [$mentioned->id]);

    Event::assertDispatched(BoardItemCommentsChanged::class, function (BoardItemCommentsChanged $event) use ($item, $author, $mentioned) {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === "private-board-comments.{$item->board_id}"
            && $payload['item_id'] === $item->id
            && $payload['comment_count'] === 2
            && $payload['mentioned_user_ids'] === [$mentioned->id]
            && $payload['actor_id'] === $author->id
            && $payload['action'] === 'posted'
            && ! array_key_exists('body', $payload);
    });
});

test('a reply counts too', function () {
    Event::fake([BoardItemCommentsChanged::class]);
    $item = createLiveCountItem();
    $author = User::factory()->create();
    $parent = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Root']);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Reply', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('item_comments.comment_count', 2);

    Event::assertDispatched(BoardItemCommentsChanged::class, fn (BoardItemCommentsChanged $event) => $event->parent_id === $parent->id);
});

test('a scheduled update is not counted or broadcast until it goes live', function () {
    Event::fake([BoardItemCommentsChanged::class]);
    $item = createLiveCountItem();
    $author = User::factory()->create();

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", [
            'body' => 'Later',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('item_comments.comment_count', 0);

    Event::assertNotDispatched(BoardItemCommentsChanged::class);

    app(ScheduledCommentService::class)->publish(BoardItemComment::firstOrFail());

    Event::assertDispatched(BoardItemCommentsChanged::class, fn (BoardItemCommentsChanged $event) => $event->summary['comment_count'] === 1);
});

test('the items index does not count scheduled updates', function () {
    $item = createLiveCountItem();
    $author = User::factory()->create();
    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Now']);
    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Later', 'scheduled_at' => now()->addDay()]);

    $this->actingAs($author, 'api')
        ->getJson("/api/boards/{$item->board_id}/items")
        ->assertOk()
        ->assertJsonPath('data.0.comment_count', 1);
});

test('deleting and restoring an update broadcast the new count', function () {
    Event::fake([BoardItemCommentsChanged::class]);
    $item = createLiveCountItem();
    $author = User::factory()->create();
    $comment = BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => 'Oops']);

    $this->actingAs($author, 'api')
        ->deleteJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}")
        ->assertOk()
        ->assertJsonPath('item_comments.comment_count', 0);

    Event::assertDispatched(BoardItemCommentsChanged::class, fn (BoardItemCommentsChanged $event) => $event->action === 'deleted' && $event->summary['comment_count'] === 0);

    $this->actingAs($author, 'api')
        ->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments/{$comment->id}/restore")
        ->assertOk()
        ->assertJsonPath('item_comments.comment_count', 1);

    Event::assertDispatched(BoardItemCommentsChanged::class, fn (BoardItemCommentsChanged $event) => $event->action === 'restored' && $event->summary['comment_count'] === 1);
});

test('comment counts returns every item count and the items where the viewer is tagged', function () {
    $item = createLiveCountItem();
    $other = $item->board->items()->create(['group_id' => $item->group_id, 'name' => 'Other', 'position' => 1]);
    $empty = $item->board->items()->create(['group_id' => $item->group_id, 'name' => 'Empty', 'position' => 2]);
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();

    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $teammate->id, 'body' => 'One']);
    BoardItemComment::create(['item_id' => $item->id, 'user_id' => $teammate->id, 'body' => 'Two'])
        ->mentions()->create(['user_id' => $viewer->id]);
    BoardItemComment::create(['item_id' => $other->id, 'user_id' => $teammate->id, 'body' => 'Three']);
    BoardItemComment::create(['item_id' => $other->id, 'user_id' => $teammate->id, 'body' => 'Later', 'scheduled_at' => now()->addDay()]);

    $data = $this->actingAs($viewer, 'api')
        ->getJson("/api/boards/{$item->board_id}/items/comment-counts")
        ->assertOk()
        ->json('data');

    expect($data['counts'])->toBe([(string) $item->id => 2, (string) $other->id => 1])
        ->and($data['counts'])->not->toHaveKey((string) $empty->id)
        ->and($data['mentioned_item_ids'])->toBe([$item->id])
        ->and($data['changed_at'])->toBeInt();
});
