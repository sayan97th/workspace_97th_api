<?php

use App\Models\BoardComment;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\FeedFollow;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @return array{0: BoardItem, 1: User}
 */
function createFeedExportTestItem(): array
{
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => 'Roadmap',
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);

    return [$board->items()->create(['group_id' => $group->id, 'name' => 'Ship it', 'position' => 0]), $member];
}

test('the feed export holds the updates of the open tab and respects its filters', function () {
    [$item, $member] = createFeedExportTestItem();
    $other = User::factory()->create();
    $item->comments()->create(['user_id' => $member->id, 'body' => 'First **bold** update']);
    $item->comments()->create(['user_id' => $other->id, 'body' => 'Second update']);
    BoardComment::create(['board_id' => $item->board_id, 'user_id' => $member->id, 'body' => 'Board wide']);

    Excel::fake();
    $this->actingAs($member, 'api')->get('/api/feed/updates/export?tab=account')->assertOk();

    Excel::assertDownloaded('update_feed_'.now()->format('Y_m_d').'.xlsx', function ($export) {
        $rows = collect($export->array());

        return $rows->count() === 3
            && $rows->contains(fn ($row) => $row[1] !== '' && $row[2] === 'Roadmap' && $row[3] === 'Ship it' && $row[8] === 'First bold update')
            && $rows->contains(fn ($row) => $row[3] === '' && $row[8] === 'Board wide');
    });

    Excel::fake();
    $this->actingAs($member, 'api')->get("/api/feed/updates/export?tab=account&author_id={$other->id}")->assertOk();

    Excel::assertDownloaded('update_feed_'.now()->format('Y_m_d').'.xlsx', fn ($export) => collect($export->array())->pluck(8)->all() === ['Second update']);
});

test('the feed export only reaches the viewer workspaces', function () {
    [$item] = createFeedExportTestItem();
    $item->comments()->create(['user_id' => User::factory()->create()->id, 'body' => 'Not yours']);

    Excel::fake();
    $this->actingAs(User::factory()->create(), 'api')->get('/api/feed/updates/export?tab=account')->assertOk();

    Excel::assertDownloaded('update_feed_'.now()->format('Y_m_d').'.xlsx', fn ($export) => $export->array() === []);
});

test('commenting on an item or being mentioned in it follows the item', function () {
    [$item, $author] = createFeedExportTestItem();
    $mentioned = User::factory()->create();
    $item->board->workspace->users()->attach($mentioned->id, ['role' => 'member']);
    $outsider = User::factory()->create();

    $this->actingAs($author, 'api')->postJson(
        "/api/boards/{$item->board_id}/items/{$item->id}/comments",
        ['body' => 'Hello', 'mentioned_user_ids' => [$mentioned->id, $outsider->id]],
    )->assertCreated();

    foreach ([$author, $mentioned] as $user) {
        expect($user->feedFollows()->where('target_type', FeedFollow::TYPE_ITEM)->where('target_id', $item->id)->exists())->toBeTrue();
    }
    // Not in the item's workspace, so nothing to follow.
    expect($outsider->feedFollows()->count())->toBe(0);

    // Posting again never duplicates the follow.
    $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Again'])->assertCreated();
    expect($author->feedFollows()->count())->toBe(1);
});

test('auto follow respects the switch and an existing board follow', function () {
    [$item, $author] = createFeedExportTestItem();
    $author->update(['auto_follow_enabled' => false]);

    $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Quiet'])->assertCreated();
    expect($author->feedFollows()->count())->toBe(0);

    $author->update(['auto_follow_enabled' => true]);
    $author->feedFollows()->create(['target_type' => FeedFollow::TYPE_BOARD, 'target_id' => $item->board_id]);

    $this->actingAs($author, 'api')->postJson("/api/boards/{$item->board_id}/items/{$item->id}/comments", ['body' => 'Loud'])->assertCreated();
    expect($author->feedFollows()->count())->toBe(1);
});

test('auto follow can be switched off in the notification preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->patchJson('/api/profile/notifications', ['auto_follow_enabled' => false])
        ->assertOk()
        ->assertJsonPath('user.auto_follow_enabled', false);

    expect($user->fresh()->auto_follow_enabled)->toBeFalse();
});
