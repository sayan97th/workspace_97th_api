<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardItemAttachment;
use App\Models\BoardItemComment;
use App\Models\BoardItemCommentMention;
use App\Models\BoardItemValue;
use App\Models\BoardTag;
use App\Models\BoardView;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createEverythingBoard(string $label, array $attributes = []): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'label' => $label,
        'view_key' => null,
        ...$attributes,
    ]);
}

function createEverythingItem(WorkspaceNavigationItem $board, string $name, array $attributes = []): BoardItem
{
    $view = BoardView::factory()->create(['board_id' => $board->id]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id]);

    return BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => $name, ...$attributes]);
}

function createEverythingComment(BoardItem $item, User $author, string $body, array $attributes = []): BoardItemComment
{
    return BoardItemComment::query()->create(['item_id' => $item->id, 'user_id' => $author->id, 'body' => $body, ...$attributes]);
}

test('guests cannot use search everything', function () {
    $this->getJson('/api/search/everything')->assertUnauthorized();
});

test('an empty query lists every visible board and returns the count of every tab', function () {
    $user = User::factory()->create();
    createEverythingBoard('Roadmap');
    createEverythingBoard('Hidden', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);
    createEverythingBoard('Spec', ['view_key' => 'doc']);

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=boards')
        ->assertOk()
        ->assertJsonPath('counts.boards', 1)
        ->assertJsonPath('counts.docs', 0)
        ->assertJsonPath('data.0.label', 'Roadmap')
        ->assertJsonPath('meta.has_more', false);
});

test('boards match by name, by item name and by item tag, with the matched items', function () {
    $user = User::factory()->create();
    $by_name = createEverythingBoard('SEO plan');
    $by_item = createEverythingBoard('Marketing');
    createEverythingItem($by_item, 'Fix seo titles');
    $by_tag = createEverythingBoard('Content');
    $tagged = createEverythingItem($by_tag, 'Landing page');
    $tag = BoardTag::factory()->create(['board_id' => $by_tag->id, 'label' => '#seo']);
    $column = BoardColumn::factory()->create(['board_id' => $by_tag->id, 'type' => 'tags']);
    BoardItemValue::factory()->create(['item_id' => $tagged->id, 'column_id' => $column->id, 'value' => [(string) $tag->id]]);
    createEverythingBoard('Unrelated');
    $longer = createEverythingBoard('Optimizations');
    $longer_tag = BoardTag::factory()->create(['board_id' => $longer->id, 'label' => '#seooptimizations']);
    $longer_column = BoardColumn::factory()->create(['board_id' => $longer->id, 'type' => 'tags']);
    BoardItemValue::factory()->create([
        'item_id' => createEverythingItem($longer, 'Audit')->id, 'column_id' => $longer_column->id, 'value' => [(string) $longer_tag->id],
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/search/everything?tab=boards&q=seo')->assertOk();

    $boards = collect($response->json('data'))->keyBy('label');
    expect($boards->keys()->sort()->values()->all())->toBe(['Content', 'Marketing', 'SEO plan'])
        ->and($boards['Content']['matched_items'][0]['name'])->toBe('Landing page')
        ->and($boards['Marketing']['matched_item_count'])->toBe(1);

    // "#seo", what clicking a tag searches for, only finds the tagged items.
    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=boards&q=%23seo')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.label', 'Content');
});

test('updates are searchable with facet counts and filters', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $item = createEverythingItem(createEverythingBoard('Board'), 'Item');
    $mine = createEverythingComment($item, $user, 'Launch checklist https://example.com');
    createEverythingComment($item, $other, 'Launch is a reply', ['parent_id' => $mine->id]);
    $mention = createEverythingComment($item, $other, 'Launch note for you');
    BoardItemCommentMention::query()->create(['comment_id' => $mention->id, 'user_id' => $user->id]);
    createEverythingComment($item, $other, 'Unrelated');
    createEverythingComment(createEverythingItem(createEverythingBoard('Old'), 'Gone', ['is_archived' => true]), $other, 'Launch archived');

    $response = $this->actingAs($user, 'api')->getJson('/api/search/everything?tab=updates&q=launch')->assertOk();

    $response->assertJsonPath('counts.updates', 3)
        ->assertJsonPath('facets.mentioned', 1)
        ->assertJsonPath('facets.wrote', 1)
        ->assertJsonPath('facets.links', 1)
        ->assertJsonPath('facets.reply', 1)
        ->assertJsonPath('facets.archived', 1)
        ->assertJsonPath('data.0.item.name', 'Item');

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=updates&q=launch&filters[]=wrote')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.excerpt', 'Launch checklist https://example.com');

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=updates&q=launch&filters[]=archived')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.excerpt', 'Launch archived');
});

test('the date filter narrows updates', function () {
    $user = User::factory()->create();
    $item = createEverythingItem(createEverythingBoard('Board'), 'Item');
    createEverythingComment($item, $user, 'Old update')->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
    createEverythingComment($item, $user, 'New update');

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=updates&date_from='.now()->subDays(7)->toDateString())
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.excerpt', 'New update');
});

test('files from item attachments and Files cells are classified by type', function () {
    $user = User::factory()->create();
    $board = createEverythingBoard('Board');
    $item = createEverythingItem($board, 'Item');
    BoardItemAttachment::query()->create([
        'item_id' => $item->id, 'uploaded_by_id' => $user->id, 'file_name' => 'brief.pdf',
        'file_path' => 'files/brief.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $column = BoardColumn::factory()->create(['board_id' => $board->id, 'type' => 'files']);
    BoardItemValue::factory()->create(['item_id' => $item->id, 'column_id' => $column->id, 'value' => [
        ['id' => 'a', 'url' => 'https://example.com/a.png', 'path' => 'cells/a.png', 'file_name' => 'logo.png', 'mime_type' => 'image/png'],
    ]]);

    $response = $this->actingAs($user, 'api')->getJson('/api/search/everything?tab=files')->assertOk();
    $response->assertJsonPath('counts.files', 2)
        ->assertJsonPath('facets.pdf', 1)
        ->assertJsonPath('facets.image', 1);

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=files&file_types[]=image')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'logo.png')
        ->assertJsonPath('data.0.item.name', 'Item');
});

test('people match every word of the term and skip deactivated accounts', function () {
    $user = User::factory()->create(['first_name' => 'Mike', 'last_name' => 'Witham']);
    User::factory()->create(['first_name' => 'Mike', 'last_name' => 'Other']);
    User::factory()->create(['first_name' => 'Mike', 'last_name' => 'Witham Gone', 'is_active' => false]);

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=people&q=mike%20witham')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.full_name', 'Mike Witham');
});

test('the tags tab lists public tags only', function () {
    $user = User::factory()->create();
    BoardTag::factory()->create(['board_id' => createEverythingBoard('Public')->id, 'label' => '#apps']);
    BoardTag::factory()->create([
        'board_id' => createEverythingBoard('Private', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE])->id,
        'label' => '#secret',
    ]);

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=tags')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.label', '#apps')
        ->assertJsonPath('data.0.usage_count', 0);
});

test('docs are only searched once a term is typed', function () {
    $user = User::factory()->create();
    createEverythingBoard('Quarterly plan', ['view_key' => 'doc']);

    $this->actingAs($user, 'api')->getJson('/api/search/everything?tab=docs')->assertJsonPath('meta.total', 0);
    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=docs&q=quarterly')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.label', 'Quarterly plan');
});

test('the all tab previews every tab', function () {
    $user = User::factory()->create(['first_name' => 'Roadie']);
    createEverythingBoard('Road trip');

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?q=road')
        ->assertOk()
        ->assertJsonPath('data.boards.0.label', 'Road trip')
        ->assertJsonPath('data.people.0.full_name', $user->full_name)
        ->assertJsonStructure(['data' => ['boards', 'updates', 'files', 'people', 'tags', 'docs'], 'counts']);
});

test('invalid filters are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson('/api/search/everything?tab=nope&filters[]=bad&date_from=2026-02-10&date_to=2026-01-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tab', 'filters.0', 'date_to']);
});

test('a search can be saved once, listed and removed by its owner only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user, 'api')->postJson('/api/search/saved', ['query' => ' seo ', 'tab' => 'tags'])->assertCreated();
    $this->actingAs($user, 'api')->postJson('/api/search/saved', ['query' => 'seo', 'tab' => 'tags'])->assertOk();

    $this->actingAs($user, 'api')->getJson('/api/search/saved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.query', 'seo');

    $saved = SavedSearch::query()->firstOrFail();
    $this->actingAs($other, 'api')->deleteJson("/api/search/saved/{$saved->id}")->assertNotFound();
    $this->actingAs($user, 'api')->deleteJson("/api/search/saved/{$saved->id}")->assertOk();

    expect(SavedSearch::query()->count())->toBe(0);
});
