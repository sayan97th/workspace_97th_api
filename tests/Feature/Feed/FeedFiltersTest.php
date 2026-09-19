<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\FeedSavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createFeedFilterItem(User $member): BoardItem
{
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => 'member']);

    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Task', 'position' => 0]);
}

/** A comment by `$author` on `$item` that mentions `$viewer`, so it lands in the viewer's feed. */
function postFeedFilterComment(BoardItem $item, User $author, User $viewer, string $body, array $attributes = []): BoardItemComment
{
    $comment = new BoardItemComment([
        'item_id' => $item->id,
        'user_id' => $author->id,
        'body' => $body,
        ...$attributes,
    ]);
    $comment->created_at = $attributes['created_at'] ?? now();
    $comment->save();
    $comment->mentions()->create(['user_id' => $viewer->id]);

    return $comment;
}

function feedFilterBodies($response): array
{
    return collect($response->json('data'))->pluck('body')->sort()->values()->all();
}

test('the feed searches update text and author names', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $grace = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach([$ada->id => ['role' => 'member'], $grace->id => ['role' => 'member']]);
    postFeedFilterComment($item, $ada, $viewer, 'Budget review is ready');
    postFeedFilterComment($item, $grace, $viewer, 'Launch checklist done');

    $by_text = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?q=budget')->assertOk();
    $by_author = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?q=grace hop')->assertOk();
    $by_percent = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?q=100%25')->assertOk();

    expect(feedFilterBodies($by_text))->toBe(['Budget review is ready'])
        ->and(feedFilterBodies($by_author))->toBe(['Launch checklist done'])
        ->and($by_percent->json('data'))->toBeEmpty();
});

test('the feed filters by author, kind and posted day', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach([$ada->id => ['role' => 'member'], $grace->id => ['role' => 'member']]);

    $root = postFeedFilterComment($item, $ada, $viewer, 'Ada root', ['created_at' => now()->subDays(10)]);
    postFeedFilterComment($item, $grace, $viewer, 'Grace root', ['created_at' => now()->subDay()]);
    postFeedFilterComment($item, $grace, $viewer, 'Grace reply', ['parent_id' => $root->id, 'created_at' => now()]);

    $by_author = $this->actingAs($viewer, 'api')->getJson("/api/feed/updates?author_id={$ada->id}");
    $updates_only = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?kind=updates');
    $replies_only = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?kind=replies');
    $recent = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?from='.now()->subDays(2)->toDateString());
    $window = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?from='.now()->subDays(12)->toDateString().'&to='.now()->subDays(5)->toDateString());

    expect(feedFilterBodies($by_author))->toBe(['Ada root'])
        ->and(feedFilterBodies($updates_only))->toBe(['Ada root', 'Grace root'])
        ->and(feedFilterBodies($replies_only))->toBe(['Grace reply'])
        ->and(feedFilterBodies($recent))->toBe(['Grace reply', 'Grace root'])
        ->and(feedFilterBodies($window))->toBe(['Ada root'])
        ->and(collect($replies_only->json('data'))->first()['is_reply'])->toBeTrue();
});

test('the unread filter drops what the viewer has already seen', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create();
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach($ada->id, ['role' => 'member']);
    $seen = postFeedFilterComment($item, $ada, $viewer, 'Already seen');
    postFeedFilterComment($item, $ada, $viewer, 'Still new');
    $seen->views()->create(['user_id' => $viewer->id]);

    $response = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?unread=1')->assertOk();

    expect(feedFilterBodies($response))->toBe(['Still new']);
});

test('mark all as read only touches what the tab, board and filters match', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach([$ada->id => ['role' => 'member'], $grace->id => ['role' => 'member']]);
    $from_ada = postFeedFilterComment($item, $ada, $viewer, 'From Ada');
    $from_grace = postFeedFilterComment($item, $grace, $viewer, 'From Grace');

    $this->actingAs($viewer, 'api')->postJson('/api/feed/updates/read-all', ['author_id' => $ada->id])
        ->assertOk()
        ->assertJsonPath('data.marked_count', 1)
        ->assertJsonPath('data.unread_count', 1);

    expect($from_ada->views()->where('user_id', $viewer->id)->exists())->toBeTrue()
        ->and($from_grace->views()->where('user_id', $viewer->id)->exists())->toBeFalse();

    $this->actingAs($viewer, 'api')->postJson('/api/feed/updates/read-all')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

test('an update can be brought back as unread', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create();
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach($ada->id, ['role' => 'member']);
    $comment = postFeedFilterComment($item, $ada, $viewer, 'Read me twice');
    $comment->views()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer, 'api')->deleteJson("/api/feed/updates/ic-{$comment->id}/seen")
        ->assertOk()
        ->assertJsonPath('data.is_unread', true);
});

test('the boards sidebar reports unread counts and the filters endpoint lists authors', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach($ada->id, ['role' => 'member']);
    postFeedFilterComment($item, $ada, $viewer, 'One');
    $seen = postFeedFilterComment($item, $ada, $viewer, 'Two');
    $seen->views()->create(['user_id' => $viewer->id]);

    $boards = $this->actingAs($viewer, 'api')->getJson('/api/feed/boards')->assertOk()->json('data');
    $filters = $this->actingAs($viewer, 'api')->getJson('/api/feed/filters')->assertOk();

    expect($boards[0]['id'])->toBe('all-boards')
        ->and($boards[0]['unread_count'])->toBe(1)
        ->and($boards[1]['unread_count'])->toBe(1)
        ->and($boards[1]['count'])->toBe(2)
        ->and($filters->json('data.authors.0.name'))->toBe('Ada Lovelace');
});

test('the feed filters apply to board discussion updates too', function () {
    $viewer = User::factory()->create();
    $ada = User::factory()->create();
    $item = createFeedFilterItem($viewer);
    $item->board->workspace->users()->attach($ada->id, ['role' => 'member']);
    $comment = BoardComment::create(['board_id' => $item->board_id, 'user_id' => $ada->id, 'body' => 'Board level news']);
    $comment->mentions()->create(['user_id' => $viewer->id]);

    $response = $this->actingAs($viewer, 'api')->getJson('/api/feed/updates?q=news&kind=updates')->assertOk();

    expect(feedFilterBodies($response))->toBe(['Board level news']);
});

test('saved views can be created, listed and deleted by their owner only', function () {
    $user = User::factory()->create();

    $created = $this->actingAs($user, 'api')->postJson('/api/feed/saved-views', [
        'name' => 'Unread from Ada',
        'tab' => 'mentioned',
        'author_id' => 7,
        'unread' => true,
    ])->assertCreated();

    $created->assertJsonPath('data.filters.tab', 'mentioned')
        ->assertJsonPath('data.filters.author_id', 7)
        ->assertJsonPath('data.filters.unread', true);

    $this->actingAs($user, 'api')->getJson('/api/feed/saved-views')->assertOk()->assertJsonCount(1, 'data');

    $id = $created->json('data.id');
    $this->actingAs(User::factory()->create(), 'api')->deleteJson("/api/feed/saved-views/{$id}")->assertForbidden();
    $this->actingAs($user, 'api')->deleteJson("/api/feed/saved-views/{$id}")->assertOk();

    expect(FeedSavedView::count())->toBe(0);
});

test('a saved view needs a name and the number of views is capped', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson('/api/feed/saved-views', ['name' => ''])->assertUnprocessable();

    foreach (range(1, FeedSavedView::MAX_PER_USER) as $index) {
        FeedSavedView::create(['user_id' => $user->id, 'name' => "View {$index}", 'filters' => []]);
    }
    $this->actingAs($user, 'api')->postJson('/api/feed/saved-views', ['name' => 'One too many'])->assertUnprocessable();
});
