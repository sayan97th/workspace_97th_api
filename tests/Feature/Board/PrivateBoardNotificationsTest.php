<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Services\Feed\FeedService;
use App\Services\Notification\NotificationService;
use App\Support\VisibleBoards;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Like monday.com, a private board is completely private: someone who was not added to it never
 * hears about it, even when they were assigned or mentioned by mistake, or were assigned back when
 * the board was still a Main board.
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
    $this->item = $this->board->items()->create(['group_id' => $group->id, 'name' => 'Quarterly plan', 'position' => 0]);

    $this->notifyAbout = fn (User $recipient, ?WorkspaceNavigationItem $board) => app(NotificationService::class)->notify(
        recipient: $recipient,
        actor: $this->creator,
        type: Notification::TYPE_ASSIGNED,
        board: $board,
        action_label: 'Assigned you',
        action_target: 'to "Quarterly plan"',
        link: "/boards/{$this->board->id}/pulses/{$this->item->id}",
        board_item: $this->item,
    );
});

test('nobody outside a private board is notified about it', function () {
    expect(($this->notifyAbout)($this->outsider, $this->board))->toBeNull();
    expect(($this->notifyAbout)($this->invitee, $this->board))->not->toBeNull();

    expect(Notification::where('user_id', $this->outsider->id)->exists())->toBeFalse();
});

test('the board is found from the item when the caller leaves it out', function () {
    expect(($this->notifyAbout)($this->outsider, null))->toBeNull();
    expect(($this->notifyAbout)($this->invitee, null))->not->toBeNull();
});

test('people still assigned from before the board went private get no due date reminder', function () {
    $this->item->values()->create(['column_id' => $this->column->id, 'value' => [$this->outsider->id, $this->invitee->id]]);

    $reached_ids = collect([$this->outsider, $this->invitee])
        ->filter(fn (User $user) => app(NotificationService::class)->notify(
            recipient: $user,
            actor: null,
            type: Notification::TYPE_DUE_DATE_REMINDER,
            board: $this->board,
            action_label: 'Due date reminder',
            action_target: '"Quarterly plan" is due today',
            link: null,
            board_item: $this->item,
        ) !== null)
        ->pluck('id')
        ->all();

    expect($reached_ids)->toBe([$this->invitee->id]);
});

test('a Main board keeps notifying the whole workspace', function () {
    $this->board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN]);

    expect(($this->notifyAbout)($this->outsider, $this->board))->not->toBeNull();
});

test('old notifications of a board that went private leave the bell of people without access', function () {
    $this->board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN]);
    ($this->notifyAbout)($this->outsider, $this->board);
    $this->board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $this->actingAs($this->outsider, 'api')->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    $this->board->collaborators()->attach($this->outsider->id, ['invited_by' => $this->creator->id]);

    $this->actingAs($this->outsider, 'api')->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);
});

test('a private board update only reaches the live feed of people on the board', function () {
    $ids = app(FeedService::class)
        ->resolveRecipients($this->board, collect([$this->outsider->id]), null)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($ids)->toBe(collect([$this->creator->id, $this->invitee->id])->sort()->values()->all());
});

test('onlyViewers keeps everyone on a Main board and the people added on a private one', function () {
    $users = collect([$this->outsider, $this->invitee, $this->creator]);

    expect(VisibleBoards::onlyViewers($this->board, $users)->pluck('id')->all())->toBe([$this->invitee->id, $this->creator->id]);

    $this->board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN]);
    expect(VisibleBoards::onlyViewers($this->board, $users)->pluck('id')->all())->toBe([$this->outsider->id, $this->invitee->id, $this->creator->id]);
});
