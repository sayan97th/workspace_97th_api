<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createFeedTestItem(User $member): BoardItem
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

test('the feed pages through updates without repeating a row and keeps pinned ones on the first page', function () {
    $user = User::factory()->create();
    $item = createFeedTestItem($user);

    // Five comments: two share the exact same minute (a tie the cursor has to
    // break by id), and the oldest is pinned.
    foreach ([1, 2, 2, 3, 4] as $index => $minutes_ago) {
        $comment = new BoardItemComment([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'body' => "Update {$index}",
            'pinned' => $index === 4,
        ]);
        $comment->created_at = now()->subMinutes($minutes_ago)->startOfMinute();
        $comment->save();
    }

    $first = $this->actingAs($user, 'api')->getJson('/api/feed/updates?limit=2')->assertOk();
    $second = $this->actingAs($user, 'api')->getJson('/api/feed/updates?limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

    // Page one: the pinned update plus the two newest. Page two: only the two remaining unpinned ones.
    expect($first->json('data'))->toHaveCount(3)
        ->and($first->json('data.0.pinned'))->toBeTrue()
        ->and($first->json('meta.has_more'))->toBeTrue()
        ->and($second->json('data'))->toHaveCount(2)
        ->and(collect($second->json('data'))->every(fn ($update) => $update['pinned'] === false))->toBeTrue()
        ->and($second->json('meta.next_cursor'))->toBeNull();

    $ids = collect([$first, $second])->flatMap(fn ($page) => collect($page->json('data'))->pluck('id'));
    expect($ids)->toHaveCount(5)->and($ids->unique())->toHaveCount(5);

    // The unpinned rows arrive strictly newest first across the page boundary.
    $bodies = collect([$first, $second])->flatMap(fn ($page) => collect($page->json('data'))->where('pinned', false)->pluck('body'))->values();
    expect($bodies->first())->toBe('Update 0')->and($bodies->last())->toBe('Update 3');
});

test('a malformed feed cursor is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->getJson('/api/feed/updates?cursor=not-a-cursor')->assertUnprocessable();
});

test('the people endpoint lists workspace members and refuses outsiders', function () {
    $member = User::factory()->create();
    $item = createFeedTestItem($member);

    $this->actingAs($member, 'api')->getJson("/api/feed/boards/{$item->board_id}/people")
        ->assertOk()
        ->assertJsonPath('data.0.id', $member->id);

    $this->actingAs(User::factory()->create(), 'api')->getJson("/api/feed/boards/{$item->board_id}/people")->assertForbidden();
});

test('a person card is only visible to people who share a workspace', function () {
    $viewer = User::factory()->create();
    $colleague = User::factory()->create(['job_title' => 'Designer']);
    $stranger = User::factory()->create();

    $workspace = Workspace::factory()->create();
    $workspace->users()->attach([$viewer->id => ['role' => 'member'], $colleague->id => ['role' => 'member']]);

    $this->actingAs($viewer, 'api')->getJson("/api/people/{$colleague->id}/card")
        ->assertOk()
        ->assertJsonPath('data.job_title', 'Designer');

    $this->actingAs($viewer, 'api')->getJson("/api/people/{$stranger->id}/card")->assertForbidden();
});
