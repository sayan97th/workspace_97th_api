<?php

use App\Enums\BoardViewType;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\BoardViewShareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;

/**
 * The Form view (builder and public submissions) and the read only public
 * "Share view" link.
 */
function createFormBoard(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'created_by_id' => $owner->id,
        'label' => 'Leads',
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id, 'name' => 'Inbox']);
    $table_view = BoardView::find($group->board_view_id);
    $email = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $table_view->id, 'type' => BoardColumn::TYPE_EMAIL, 'label' => 'Email', 'position' => 1]);
    $status = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $table_view->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Stage',
        'position' => 2,
        'config' => ['options' => [['id' => 'new', 'label' => 'New', 'color' => '#579bfc'], ['id' => 'won', 'label' => 'Won', 'color' => '#00c875']]],
    ]);
    $form_view = BoardView::factory()->create([
        'board_id' => $board->id,
        'label' => 'Contact form',
        'view_type' => BoardViewType::Form->value,
        'is_primary' => false,
        'position' => 1,
    ]);

    return [$owner, $workspace, $board, $group, $table_view, $email, $status, $form_view];
}

// ── Form builder ───────────────────────────────────────────────────────────

test('opening the form builder creates a public token and lists every supported column', function () {
    [$owner, , $board, , $table_view, $email, $status, $form_view] = createFormBoard();

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/boards/{$board->id}/views/{$form_view->id}/form")
        ->assertOk()
        ->assertJsonPath('config.source_view_id', $table_view->id)
        ->assertJsonPath('config.title', 'Leads');

    expect($response->json('token'))->toBeString()->toHaveLength(40);
    expect(collect($response->json('config.questions'))->pluck('column_id')->all())->toBe([$email->id, $status->id]);
});

test('the form builder refuses a view that is not a form', function () {
    [$owner, , $board, , $table_view] = createFormBoard();

    $this->actingAs($owner, 'api')
        ->getJson("/api/boards/{$board->id}/views/{$table_view->id}/form")
        ->assertStatus(422);
});

test('members without structure access cannot change the form', function () {
    [, $workspace, $board, , , , , $form_view] = createFormBoard();
    $board->update(['edit_permission' => 'content']);
    $member = User::factory()->create();
    DB::table('workspace_user')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", ['title' => 'Hijacked'])
        ->assertForbidden();
});

// ── Public form ────────────────────────────────────────────────────────────

test('a public submission creates an item with its answers in the target group', function () {
    [$owner, , $board, $group, , $email, $status, $form_view] = createFormBoard();
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", [
        'target_group_id' => $group->id,
        'questions' => [
            ['column_id' => $email->id, 'is_required' => true, 'is_visible' => true],
            ['column_id' => $status->id, 'is_required' => false, 'is_visible' => true],
        ],
    ])->assertOk();
    $token = $form_view->fresh()->form_token;
    auth()->forgetGuards();

    $this->getJson("/api/public/forms/{$token}")
        ->assertOk()
        ->assertJsonPath('title', 'Leads')
        ->assertJsonPath('questions.0.type', 'email')
        ->assertJsonPath('questions.1.options.1.label', 'Won');

    $this->postJson("/api/public/forms/{$token}/submissions", [
        'name' => 'Ada Lovelace',
        'answers' => [(string) $email->id => 'ada@example.com', (string) $status->id => 'won'],
    ])->assertCreated();

    $item = BoardItem::where('board_id', $board->id)->where('name', 'Ada Lovelace')->firstOrFail();
    expect($item->group_id)->toBe($group->id);
    expect($item->values->pluck('value', 'column_id')->all())->toMatchArray([$email->id => 'ada@example.com', $status->id => 'won']);
});

test('a public submission is validated per question type', function () {
    [$owner, , $board, , , $email, $status, $form_view] = createFormBoard();
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", [
        'questions' => [['column_id' => $email->id, 'is_required' => true, 'is_visible' => true], ['column_id' => $status->id, 'is_visible' => true]],
    ]);
    $token = $form_view->fresh()->form_token;
    auth()->forgetGuards();

    $this->postJson("/api/public/forms/{$token}/submissions", [
        'name' => '',
        'answers' => [(string) $status->id => 'not-an-option'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', "answers.{$email->id}", "answers.{$status->id}"]);

    expect(BoardItem::where('board_id', $board->id)->count())->toBe(0);
});

test('hidden questions are not shown and their answers are ignored', function () {
    [$owner, , $board, , , $email, $status, $form_view] = createFormBoard();
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", [
        'questions' => [['column_id' => $email->id, 'is_visible' => true], ['column_id' => $status->id, 'is_visible' => false]],
    ]);
    $token = $form_view->fresh()->form_token;
    auth()->forgetGuards();

    $this->getJson("/api/public/forms/{$token}")->assertJsonCount(1, 'questions');
    $this->postJson("/api/public/forms/{$token}/submissions", [
        'name' => 'Sneaky',
        'answers' => [(string) $status->id => 'won'],
    ])->assertCreated();

    expect(BoardItem::where('name', 'Sneaky')->first()->values)->toHaveCount(0);
});

test('an inactive form and an unknown token both answer 404', function () {
    [$owner, , $board, , , , , $form_view] = createFormBoard();
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", ['is_active' => false]);
    $token = $form_view->fresh()->form_token;
    auth()->forgetGuards();

    $this->getJson("/api/public/forms/{$token}")->assertNotFound();
    $this->getJson('/api/public/forms/unknown-token')->assertNotFound();
});

test('regenerating the form link breaks the old one', function () {
    [$owner, , $board, , , , , $form_view] = createFormBoard();
    $this->actingAs($owner, 'api')->getJson("/api/boards/{$board->id}/views/{$form_view->id}/form");
    $old_token = $form_view->fresh()->form_token;

    $new_token = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$form_view->id}/form/regenerate-link")
        ->assertOk()
        ->json('token');
    auth()->forgetGuards();

    expect($new_token)->not->toBe($old_token);
    $this->getJson("/api/public/forms/{$old_token}")->assertNotFound();
    $this->getJson("/api/public/forms/{$new_token}")->assertOk();
});

// ── Share view link ────────────────────────────────────────────────────────

test('a board owner can share a view and anyone with the link sees it read only', function () {
    [$owner, , $board, $group, $table_view, $email] = createFormBoard();
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id, 'name' => 'Visible lead']);
    $item->values()->create(['column_id' => $email->id, 'value' => 'lead@example.com']);

    $token = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link")
        ->assertCreated()
        ->json('link.token');
    auth()->forgetGuards();

    $this->postJson("/api/public/views/{$token}")
        ->assertOk()
        ->assertJsonPath('board.label', 'Leads')
        ->assertJsonPath('items.0.name', 'Visible lead')
        ->assertJsonPath("items.0.values.{$email->id}", 'lead@example.com');
});

test('a view restricted column never reaches a public link', function () {
    [$owner, , $board, $group, $table_view, $email] = createFormBoard();
    $email->update(['view_restriction' => ['user_ids' => [], 'team_ids' => []]]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $item->values()->create(['column_id' => $email->id, 'value' => 'secret@example.com']);
    $token = $this->actingAs($owner, 'api')->postJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link")->json('link.token');
    auth()->forgetGuards();

    $this->postJson("/api/public/views/{$token}")
        ->assertOk()
        ->assertJsonMissing(['secret@example.com'])
        ->assertJsonMissing(['label' => 'Email']);
});

test('a password protected link asks for the password', function () {
    [$owner, , $board, , $table_view] = createFormBoard();
    $this->actingAs($owner, 'api')->postJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link");
    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link", ['password' => 'open sesame'])
        ->assertOk()
        ->assertJsonPath('link.has_password', true);
    $token = BoardViewShareLink::first()->token;
    auth()->forgetGuards();

    $this->postJson("/api/public/views/{$token}")->assertUnauthorized()->assertJsonPath('requires_password', true);
    $this->postJson("/api/public/views/{$token}", ['password' => 'wrong'])->assertUnauthorized();
    $this->postJson("/api/public/views/{$token}", ['password' => 'open sesame'])->assertOk();
});

test('a disabled link and members who are not owners are both rejected', function () {
    [$owner, $workspace, $board, , $table_view] = createFormBoard();
    $member = User::factory()->create();
    DB::table('workspace_user')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);

    $this->actingAs($member, 'api')->postJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link")->assertForbidden();

    $token = $this->actingAs($owner, 'api')->postJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link")->json('link.token');
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$table_view->id}/share-link", ['is_enabled' => false])->assertOk();
    auth()->forgetGuards();

    $this->postJson("/api/public/views/{$token}")->assertNotFound();
});
