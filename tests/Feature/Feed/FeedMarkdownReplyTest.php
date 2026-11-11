<?php

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

/** An item on a fresh board whose workspace holds every given member. */
function createMarkdownReplyItem(User ...$members): BoardItem
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

    return $board->items()->create(['group_id' => $group->id, 'name' => 'Launch plan', 'position' => 0]);
}

function postMarkdownReplyRoot(BoardItem $item, User $author, string $body): BoardItemComment
{
    return BoardItemComment::create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => $body]);
}

test('a Markdown reply from the feed is stored as typed, with its plain text twin', function () {
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $item = createMarkdownReplyItem($viewer, $teammate);
    $root = postMarkdownReplyRoot($item, $teammate, 'Draft is ready');

    $body = "## Notes\r\n\r\n- **Bold** point\r\n- [Spec](https://example.com/spec)\r\n\r\n> Quoted line";

    $response = $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$root->id}/reply", ['body' => "  {$body}\n\n"])
        ->assertCreated();

    $reply = BoardItemComment::where('parent_id', $root->id)->sole();

    expect($reply->body)->toBe("## Notes\n\n- **Bold** point\n- [Spec](https://example.com/spec)\n\n> Quoted line")
        ->and($reply->text_body)->toBe('Notes Bold point Spec Quoted line')
        ->and($response->json('data.body'))->toBe($reply->body);
});

test('a reply made only of Markdown syntax is rejected', function () {
    $viewer = User::factory()->create();
    $item = createMarkdownReplyItem($viewer);
    $root = postMarkdownReplyRoot($item, $viewer, 'Draft is ready');

    $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$root->id}/reply", ['body' => "**  **\n\n> \n\n- "])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    expect(BoardItemComment::where('parent_id', $root->id)->exists())->toBeFalse();
});

test('a reply holding only an inline image is accepted', function () {
    $viewer = User::factory()->create();
    $item = createMarkdownReplyItem($viewer);
    $root = postMarkdownReplyRoot($item, $viewer, 'Draft is ready');

    $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$root->id}/reply", ['body' => '![](https://example.com/shot.png)'])
        ->assertCreated();

    expect(BoardItemComment::where('parent_id', $root->id)->sole()->body)->toBe('![](https://example.com/shot.png)');
});

test('a scheduled Markdown reply is trimmed and still needs a future time', function () {
    $viewer = User::factory()->create();
    $item = createMarkdownReplyItem($viewer);
    $root = postMarkdownReplyRoot($item, $viewer, 'Draft is ready');

    $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$root->id}/schedule", ['body' => '*Later*'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');

    $this->actingAs($viewer, 'api')
        ->postJson("/api/feed/updates/ic-{$root->id}/schedule", [
            'body' => "\n*Later*\n",
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertCreated();

    expect(BoardItemComment::withoutGlobalScopes()->where('parent_id', $root->id)->sole()->body)->toBe('*Later*');
});

test('editing an update keeps Markdown and skips a revision when nothing changed', function () {
    $viewer = User::factory()->create();
    $item = createMarkdownReplyItem($viewer);
    $root = postMarkdownReplyRoot($item, $viewer, "**Plan**\n\n1. Ship");

    // The same body with CRLF line breaks is not a change.
    $this->actingAs($viewer, 'api')
        ->patchJson("/api/feed/updates/ic-{$root->id}", ['body' => "**Plan**\r\n\r\n1. Ship"])
        ->assertOk();

    expect($root->revisions()->count())->toBe(0);

    $this->actingAs($viewer, 'api')
        ->patchJson("/api/feed/updates/ic-{$root->id}", ['body' => "**Plan**\n\n1. Ship\n2. ~~Wait~~"])
        ->assertOk();

    expect($root->fresh()->body)->toBe("**Plan**\n\n1. Ship\n2. ~~Wait~~")
        ->and($root->revisions()->count())->toBe(1);

    $this->actingAs($viewer, 'api')
        ->patchJson("/api/feed/updates/ic-{$root->id}", ['body' => '__ __'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});
