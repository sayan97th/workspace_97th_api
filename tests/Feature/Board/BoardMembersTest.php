<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItemComment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\BoardMembers;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Only the people added to a board can be assigned in its People columns, `@mentioned` or
 * notified in its updates, and are listed by its members endpoint. On a private board that is
 * its creator, owner and invited collaborators, on any other board the whole workspace too.
 */
beforeEach(function () {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);

    $this->creator = User::factory()->create(['first_name' => 'Amanda']);
    $this->invitee = User::factory()->create(['first_name' => 'Brandon']);
    $this->outsider = User::factory()->create(['first_name' => 'Britni']);

    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->creator->id, ['role' => 'owner']);
    $this->workspace->users()->attach($this->invitee->id, ['role' => 'member']);
    $this->workspace->users()->attach($this->outsider->id, ['role' => 'member']);

    $this->board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        'created_by_id' => $this->creator->id,
    ]);
    $this->board->collaborators()->attach($this->invitee->id, ['invited_by' => $this->creator->id]);

    $group = BoardGroup::factory()->create(['board_id' => $this->board->id]);
    $this->column = BoardColumn::factory()->create(['board_id' => $this->board->id, 'type' => BoardColumn::TYPE_PEOPLE]);
    $this->item = $this->board->items()->create(['group_id' => $group->id, 'name' => 'Advertising Training Website', 'position' => 0]);
});

test('a private board lists only its creator and invited people', function () {
    $this->actingAs($this->creator, 'api')
        ->getJson("/api/boards/{$this->board->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->creator->id)
        ->assertJsonPath('data.1.id', $this->invitee->id);
});

test('a main board lists the whole workspace and its invited guests, never deactivated accounts', function () {
    $this->board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN]);
    $guest = User::factory()->create(['first_name' => 'Ernesto']);
    $this->board->collaborators()->attach($guest->id, ['invited_by' => $this->creator->id]);
    $this->outsider->update(['is_active' => false]);

    $ids = collect($this->actingAs($this->creator, 'api')
        ->getJson("/api/boards/{$this->board->id}/members")
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$this->creator->id, $this->invitee->id, $guest->id]);
});

test('onlyMembers keeps order and drops outsiders, duplicates and junk', function () {
    $ids = BoardMembers::onlyMembers($this->board, [$this->invitee->id, $this->outsider->id, 'x', $this->invitee->id, $this->creator->id]);

    expect($ids->all())->toBe([$this->invitee->id, $this->creator->id]);
});

test('a People column only takes newly assigned people who were added to the board', function () {
    $this->actingAs($this->creator, 'api')->patchJson("/api/boards/{$this->board->id}/items/{$this->item->id}/values", [
        'values' => [(string) $this->column->id => [$this->invitee->id, $this->outsider->id]],
    ])->assertOk();

    expect($this->item->values()->where('column_id', $this->column->id)->value('value'))->toBe([$this->invitee->id]);
    expect(Notification::where('user_id', $this->invitee->id)->where('type', Notification::TYPE_ASSIGNED)->exists())->toBeTrue();
    expect(Notification::where('user_id', $this->outsider->id)->exists())->toBeFalse();
});

test('someone already assigned stays on the item after losing access', function () {
    $this->item->values()->create(['column_id' => $this->column->id, 'value' => [$this->outsider->id]]);

    $this->actingAs($this->creator, 'api')->patchJson("/api/boards/{$this->board->id}/items/{$this->item->id}/values", [
        'values' => [(string) $this->column->id => [$this->outsider->id, $this->invitee->id]],
    ])->assertOk();

    expect($this->item->values()->where('column_id', $this->column->id)->value('value'))->toBe([$this->outsider->id, $this->invitee->id]);
});

test('a comment only mentions and notifies people added to the board', function () {
    $this->actingAs($this->creator, 'api')->post("/api/boards/{$this->board->id}/items/{$this->item->id}/comments", [
        'body' => 'Please review',
        'mentioned_user_ids' => [$this->invitee->id, $this->outsider->id],
        'notified_user_ids' => [$this->outsider->id],
    ], ['Accept' => 'application/json'])->assertCreated();

    $comment = BoardItemComment::query()->latest('id')->firstOrFail();

    expect($comment->mentions()->pluck('user_id')->all())->toBe([$this->invitee->id]);
    expect(Notification::where('user_id', $this->invitee->id)->where('type', Notification::TYPE_MENTIONED)->exists())->toBeTrue();
    expect(Notification::where('user_id', $this->outsider->id)->exists())->toBeFalse();
});

test('the feed reply roster only lists people added to the board', function () {
    $this->actingAs($this->creator, 'api')
        ->getJson("/api/feed/boards/{$this->board->id}/people")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
