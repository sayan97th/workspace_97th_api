<?php

use App\Models\BoardDoc;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function createDocTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'view_key' => 'doc',
        'parent_id' => null,
    ]);
}

function sampleDocContent(string $text = 'Hello'): string
{
    return json_encode([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => $text]]],
        ],
    ]);
}

test('a doc that was never saved returns empty content', function () {
    $user = User::factory()->create();
    $board = createDocTestBoard();

    $this->actingAs($user, 'api')
        ->getJson("/api/boards/{$board->id}/doc")
        ->assertOk()
        ->assertJsonPath('board_id', $board->id)
        ->assertJsonPath('content', null);
});

test('saving a doc stores its content and who saved it', function () {
    $user = User::factory()->create();
    $board = createDocTestBoard();
    $content = sampleDocContent();

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/doc", ['content' => $content])
        ->assertOk()
        ->assertJsonPath('doc.content', $content)
        ->assertJsonPath('doc.updated_by.id', $user->id);

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/doc", ['content' => sampleDocContent('Second')])
        ->assertOk();

    expect(BoardDoc::where('board_id', $board->id)->count())->toBe(1);
    expect(BoardDoc::where('board_id', $board->id)->value('content'))->toContain('Second');
});

test('saving a doc rejects content that is not json', function () {
    $user = User::factory()->create();
    $board = createDocTestBoard();

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/doc", ['content' => 'not json'])
        ->assertInvalid(['content']);
});

test('a workspace viewer can read a doc but not edit it', function () {
    $user = User::factory()->create();
    $board = createDocTestBoard();
    DB::table('workspace_user')->insert([
        'workspace_id' => $board->workspace_id,
        'user_id' => $user->id,
        'role' => 'viewer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user, 'api')->getJson("/api/boards/{$board->id}/doc")->assertOk();

    $this->actingAs($user, 'api')
        ->putJson("/api/boards/{$board->id}/doc", ['content' => sampleDocContent()])
        ->assertForbidden();
});

test('a file can be uploaded into a doc', function () {
    Storage::fake(config('filesystems.app_disk'));
    $user = User::factory()->create();
    $board = createDocTestBoard();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/doc/files", [
            'file' => UploadedFile::fake()->image('cover.png', 400, 200),
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'cover.png');

    expect($response->json('url'))->toContain("board-docs/{$board->id}/");
});

test('executable files are refused', function () {
    Storage::fake(config('filesystems.app_disk'));
    $user = User::factory()->create();
    $board = createDocTestBoard();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/doc/files", [
            'file' => UploadedFile::fake()->create('script.php', 10),
        ])
        ->assertInvalid(['file']);
});

test('duplicating a doc copies its body', function () {
    $user = User::factory()->create();
    $board = createDocTestBoard();
    BoardDoc::create(['board_id' => $board->id, 'content' => sampleDocContent('Copied')]);
    $workspace = $board->workspace;

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/workspaces/{$workspace->slug}/navigation/{$board->id}/duplicate")
        ->assertSuccessful();

    $copy_id = $response->json('id') ?? $response->json('item.id') ?? $response->json('data.id');
    expect(BoardDoc::where('board_id', $copy_id)->value('content'))->toContain('Copied');
});

test('a doc owner can share a public link and anyone can open it until it is turned off', function () {
    $owner = User::factory()->create();
    $board = createDocTestBoard();
    $board->update(['created_by_id' => $owner->id]);
    BoardDoc::create(['board_id' => $board->id, 'content' => sampleDocContent('Public')]);

    $token = $this->actingAs($owner, 'api')
        ->putJson("/api/boards/{$board->id}/doc/public-link", ['is_enabled' => true])
        ->assertOk()
        ->assertJsonPath('doc.public_link.is_enabled', true)
        ->json('doc.public_link.token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/public/docs/{$token}")
        ->assertOk()
        ->assertJsonPath('label', $board->label)
        ->assertJsonPath('creator.full_name', $owner->full_name);

    $this->actingAs($owner, 'api')
        ->putJson("/api/boards/{$board->id}/doc/public-link", ['is_enabled' => false])
        ->assertOk()
        ->assertJsonPath('doc.public_link.token', null);

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/public/docs/{$token}")->assertNotFound();

    // Turning it back on restores the same url.
    $this->actingAs($owner, 'api')
        ->putJson("/api/boards/{$board->id}/doc/public-link", ['is_enabled' => true])
        ->assertJsonPath('doc.public_link.token', $token);
});

test('only a doc owner can share a public link', function () {
    $member = User::factory()->create();
    $board = createDocTestBoard();
    DB::table('workspace_user')->insert([
        'workspace_id' => $board->workspace_id,
        'user_id' => $member->id,
        'role' => 'member',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($member, 'api')
        ->putJson("/api/boards/{$board->id}/doc/public-link", ['is_enabled' => true])
        ->assertForbidden();
});
