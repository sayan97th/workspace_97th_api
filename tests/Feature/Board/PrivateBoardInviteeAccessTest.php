<?php

use App\Models\AccountTeam;
use App\Models\BoardInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * People invited to a private board, by email or from the member picker, must be able to open
 * it once they accept. Covers the accept flow end to end, email letter case, and the repair
 * and diagnose commands support uses when a collaborator row went missing.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->private_board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE,
        'created_by_id' => $this->owner->id,
    ]);
});

test('an existing account invited by email opens the private board after accepting', function () {
    Mail::fake();
    $invitee = User::factory()->create(['email' => 'rachel@example.com', 'password' => 'Password123!']);

    $this->actingAs($this->owner, 'api')
        ->postJson("/api/boards/{$this->private_board->id}/invitations", ['emails' => ['rachel@example.com']])
        ->assertCreated();
    auth('api')->forgetUser();

    $invitation = BoardInvitation::query()->firstOrFail();
    $token = $this->postJson("/api/auth/board-invitations/{$invitation->code}/accept", ['password' => 'Password123!'])
        ->assertOk()
        ->json('access_token');
    auth('api')->forgetUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/boards/{$this->private_board->id}")
        ->assertOk();

    expect(DB::table('board_collaborators')->where('board_id', $this->private_board->id)->where('user_id', $invitee->id)->exists())->toBeTrue();
});

test('an invitation typed with different letter case reaches the existing account', function () {
    $invitee = User::factory()->create(['email' => 'rachel@example.com', 'password' => 'Password123!']);
    $invitation = BoardInvitation::factory()->create([
        'board_id' => $this->private_board->id,
        'email' => 'Rachel@Example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->getJson("/api/auth/board-invitations/{$invitation->code}")
        ->assertOk()
        ->assertJsonPath('account_exists', true);

    $this->postJson("/api/auth/board-invitations/{$invitation->code}/accept", ['password' => 'Password123!'])
        ->assertOk()
        ->assertJsonPath('user.id', $invitee->id);

    expect(User::query()->count())->toBe(2);
    expect($this->private_board->collaborators()->whereKey($invitee->id)->exists())->toBeTrue();
});

test('the sync command restores access lost by accepted invitees and team members', function () {
    $invitee = User::factory()->create(['email' => 'rachel@example.com']);
    BoardInvitation::factory()->create([
        'board_id' => $this->private_board->id,
        'email' => 'RACHEL@example.com',
        'invited_by' => $this->owner->id,
        'accepted_at' => now(),
    ]);

    $team_member = User::factory()->create();
    $team = AccountTeam::factory()->create();
    $team->members()->attach($team_member->id);
    DB::table('account_team_boards')->insert([
        'account_team_id' => $team->id,
        'board_id' => $this->private_board->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($invitee, 'api')->getJson("/api/boards/{$this->private_board->id}")->assertForbidden();

    $this->artisan('board:sync-collaborators', ['--dry-run' => true])->assertSuccessful();
    expect($this->private_board->collaborators()->count())->toBe(0);

    $this->artisan('board:sync-collaborators')->assertSuccessful();
    $this->artisan('board:sync-collaborators')->expectsOutputToContain('already has board access')->assertSuccessful();

    expect($this->private_board->collaborators()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$invitee->id, $team_member->id])->sort()->values()->all());

    $this->actingAs($invitee, 'api')->getJson("/api/boards/{$this->private_board->id}")->assertOk();
});

test('the diagnose command flags a second account with the same name', function () {
    $invited = User::factory()->create(['first_name' => 'Rachel', 'last_name' => 'Stone', 'email' => 'rachel@example.com']);
    User::factory()->create(['first_name' => 'Rachel', 'last_name' => 'Stone', 'email' => 'rachel.stone@example.com']);
    $this->private_board->collaborators()->attach($invited->id, ['invited_by' => $this->owner->id]);

    $this->artisan('board:diagnose-access', ['board' => $this->private_board->id, 'email' => 'rachel.stone@example.com'])
        ->expectsOutputToContain('Another account with the same name')
        ->assertSuccessful();
});

test('a workspace owner picked from the member list is added to a private board instead of skipped', function () {
    $workspace_owner = User::factory()->create();
    $this->workspace->users()->attach($workspace_owner->id, ['role' => 'owner']);

    $this->actingAs($workspace_owner, 'api')->getJson("/api/boards/{$this->private_board->id}")->assertForbidden();

    $this->actingAs($this->owner, 'api')
        ->postJson("/api/boards/{$this->private_board->id}/collaborators", ['user_ids' => [$workspace_owner->id]])
        ->assertCreated()
        ->assertJsonPath('message', 'Access granted.')
        ->assertJsonPath('data.0.id', $workspace_owner->id);

    $this->actingAs($workspace_owner, 'api')->getJson("/api/boards/{$this->private_board->id}")->assertOk();
});

test('a workspace owner invited by email to a private board gets an invitation instead of being skipped', function () {
    Mail::fake();
    $workspace_owner = User::factory()->create(['email' => 'rachel@example.com']);
    $this->workspace->users()->attach($workspace_owner->id, ['role' => 'owner']);

    $this->actingAs($this->owner, 'api')
        ->postJson("/api/boards/{$this->private_board->id}/invitations", ['emails' => ['rachel@example.com']])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(0, 'skipped');
});

test('a workspace owner is still skipped on a main board they can already open', function () {
    $workspace_owner = User::factory()->create();
    $this->workspace->users()->attach($workspace_owner->id, ['role' => 'owner']);
    $this->private_board->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN]);

    $this->actingAs($this->owner, 'api')
        ->postJson("/api/boards/{$this->private_board->id}/collaborators", ['user_ids' => [$workspace_owner->id]])
        ->assertCreated()
        ->assertJsonPath('message', 'Everyone already has access.');
});
