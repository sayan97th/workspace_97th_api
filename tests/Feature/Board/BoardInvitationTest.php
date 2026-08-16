<?php

use App\Mail\BoardInvitationMail;
use App\Models\BoardInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Accepting an invitation for a brand-new email assigns the global
    // "client" role, same as register() — needs the role catalog seeded.
    $this->seed(RolePermissionSeeder::class);
});

function createInvitationTestBoard(Workspace $workspace, ?User $creator = null, string $board_type = WorkspaceNavigationItem::BOARD_TYPE_MAIN): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'board_type' => $board_type,
        'created_by_id' => $creator?->id,
    ]);
}

test('a workspace owner can invite someone to view a board by email', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['invited@example.com'],
            'message' => 'Take a look at this one.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.0.email', 'invited@example.com')
        ->assertJsonPath('data.0.status', 'pending');

    $this->assertDatabaseHas('board_invitations', [
        'board_id' => $board->id,
        'email' => 'invited@example.com',
    ]);

    Mail::assertSent(BoardInvitationMail::class);
});

test('a board creator who is not a workspace owner can invite people to their own board', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($creator->id, ['role' => 'member']);
    $board = createInvitationTestBoard($workspace, $creator);

    $response = $this->actingAs($creator, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['invited@example.com'],
        ]);

    $response->assertCreated();
});

test('a plain member cannot invite people to a board they did not create', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = createInvitationTestBoard($workspace, $owner);

    $response = $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['invited@example.com'],
        ]);

    $response->assertStatus(403);
});

test('a board owner is skipped instead of re-invited', function () {
    Mail::fake();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['owner@example.com'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('skipped.0.email', 'owner@example.com')
        ->assertJsonPath('skipped.0.reason', 'already_has_access')
        ->assertJsonCount(0, 'data');

    Mail::assertNothingSent();
});

test('an existing workspace member is skipped for a main board they can already see', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = createInvitationTestBoard($workspace, null, WorkspaceNavigationItem::BOARD_TYPE_MAIN);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['member@example.com'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('skipped.0.reason', 'already_has_access')
        ->assertJsonCount(0, 'data');
});

test('an existing workspace member can still be invited to a private board', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $board = createInvitationTestBoard($workspace, null, WorkspaceNavigationItem::BOARD_TYPE_PRIVATE);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/boards/{$board->id}/invitations", [
            'emails' => ['member@example.com'],
        ]);

    $response->assertCreated()->assertJsonCount(1, 'data');
});

test('the board invitation roster lists owners, collaborators, and pending invites', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);
    $board->collaborators()->attach($collaborator->id, ['invited_by' => $owner->id]);
    BoardInvitation::factory()->create([
        'board_id' => $board->id,
        'email' => 'pending@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')->getJson("/api/boards/{$board->id}/invitations");

    $response->assertOk()->assertJsonCount(3, 'data');
    $kinds = collect($response->json('data'))->pluck('kind')->all();
    expect($kinds)->toContain('owner', 'collaborator', 'invitation');
});

test('an unknown email accepting a board invitation creates an account and gains view access', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $invitation = BoardInvitation::factory()->create([
        'board_id' => $board->id,
        'email' => 'newperson@example.com',
        'invited_by' => $owner->id,
    ]);

    $preview = $this->getJson("/api/auth/board-invitations/{$invitation->code}");
    $preview->assertOk()
        ->assertJsonPath('account_exists', false)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('board.id', $board->id);

    $response = $this->postJson("/api/auth/board-invitations/{$invitation->code}/accept", [
        'first_name' => 'New',
        'last_name' => 'Person',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'user']);

    $user = User::where('email', 'newperson@example.com')->first();
    expect($user)->not->toBeNull();
    expect($board->fresh()->collaborators()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($workspace->fresh()->users()->where('user_id', $user->id)->first()->pivot->role)->toBe('viewer');
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('accepting a board invitation does not downgrade an existing higher workspace role', function () {
    $owner = User::factory()->create();
    $already_member = User::factory()->create(['email' => 'existing@example.com', 'password' => 'correct-password']);
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $workspace->users()->attach($already_member->id, ['role' => 'member']);
    $board = createInvitationTestBoard($workspace);

    $invitation = BoardInvitation::factory()->create([
        'board_id' => $board->id,
        'email' => 'existing@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson("/api/auth/board-invitations/{$invitation->code}/accept", [
        'password' => 'correct-password',
    ]);

    $response->assertOk();
    expect($workspace->fresh()->users()->where('user_id', $already_member->id)->first()->pivot->role)->toBe('member');
    expect($board->fresh()->collaborators()->where('user_id', $already_member->id)->exists())->toBeTrue();
});

test('an expired board invitation cannot be accepted', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $invitation = BoardInvitation::factory()->expired()->create([
        'board_id' => $board->id,
        'email' => 'toolate@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson("/api/auth/board-invitations/{$invitation->code}/accept", [
        'first_name' => 'Too',
        'last_name' => 'Late',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertUnprocessable();
    expect(User::where('email', 'toolate@example.com')->exists())->toBeFalse();
});

test('a board owner can revoke a pending board invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $invitation = BoardInvitation::factory()->create([
        'board_id' => $board->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/boards/{$board->id}/invitations/{$invitation->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('board_invitations', ['id' => $invitation->id]);
});

test('a board owner can remove a collaborator\'s access', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);
    $board->collaborators()->attach($collaborator->id, ['invited_by' => $owner->id]);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/boards/{$board->id}/collaborators/{$collaborator->id}");

    $response->assertOk();
    expect($board->fresh()->collaborators()->where('user_id', $collaborator->id)->exists())->toBeFalse();
});

test('a board invitation can be declined', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);
    $board = createInvitationTestBoard($workspace);

    $invitation = BoardInvitation::factory()->create([
        'board_id' => $board->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson("/api/auth/board-invitations/{$invitation->code}/decline");

    $response->assertOk();
    $this->assertDatabaseMissing('board_invitations', ['id' => $invitation->id]);
});
