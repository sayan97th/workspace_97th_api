<?php

use App\Models\AccountTeam;
use App\Models\User;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function teamUserWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('a sub-team can be created under a parent team and is counted on the parent', function () {
    $admin = teamUserWithRole('admin');
    $parent = AccountTeam::factory()->create();

    $this->actingAs($admin, 'api')
        ->postJson('/api/account-teams', ['name' => 'Design', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('team.parent_id', (string) $parent->id);

    $this->actingAs($admin, 'api')
        ->getJson("/api/account-teams/{$parent->id}")
        ->assertOk()
        ->assertJsonPath('sub_team_count', 1);
});

test('a team cannot be moved under itself or one of its sub-teams', function () {
    $admin = teamUserWithRole('admin');
    $parent = AccountTeam::factory()->create();
    $child = AccountTeam::factory()->create(['parent_id' => $parent->id]);
    $grandchild = AccountTeam::factory()->create(['parent_id' => $child->id]);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/account-teams/{$parent->id}", ['parent_id' => $parent->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/account-teams/{$parent->id}", ['parent_id' => $grandchild->id])
        ->assertUnprocessable();

    $this->actingAs($admin, 'api')
        ->patchJson("/api/account-teams/{$grandchild->id}", ['parent_id' => null])
        ->assertOk()
        ->assertJsonPath('team.parent_id', null);
});

test('deleting a team moves its sub-teams up to its own parent', function () {
    $admin = teamUserWithRole('admin');
    $root = AccountTeam::factory()->create();
    $middle = AccountTeam::factory()->create(['parent_id' => $root->id]);
    $leaf = AccountTeam::factory()->create(['parent_id' => $middle->id]);

    $this->actingAs($admin, 'api')->deleteJson("/api/account-teams/{$middle->id}")->assertOk();

    expect($leaf->fresh()->parent_id)->toBe($root->id);
});

test('guests can only join a team that allows guests', function () {
    $admin = teamUserWithRole('admin');
    $guest = teamUserWithRole('client');

    $this->actingAs($admin, 'api')
        ->postJson('/api/account-teams', ['name' => 'Staff only', 'member_ids' => [$guest->id]])
        ->assertUnprocessable();

    $response = $this->actingAs($admin, 'api')
        ->postJson('/api/account-teams', ['name' => 'With guests', 'allow_guests' => true, 'member_ids' => [$guest->id]])
        ->assertCreated()
        ->assertJsonPath('team.allow_guests', true)
        ->assertJsonPath('team.member_count', 1);

    $team_id = $response->json('team.id');

    $this->actingAs($admin, 'api')
        ->getJson("/api/account-teams/{$team_id}/members")
        ->assertJsonPath('data.0.is_guest', true);

    // Guests cannot be turned off while one is still on the roster.
    $this->actingAs($admin, 'api')
        ->patchJson("/api/account-teams/{$team_id}", ['allow_guests' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allow_guests');
});

test('the candidate directory includes guests only when asked or when the team allows them', function () {
    $admin = teamUserWithRole('admin');
    teamUserWithRole('client');
    $guest_team = AccountTeam::factory()->create(['allow_guests' => true]);

    $this->actingAs($admin, 'api')->getJson('/api/account-team-candidates')->assertJsonCount(1, 'data');
    $this->actingAs($admin, 'api')->getJson('/api/account-team-candidates?include_guests=1')->assertJsonCount(2, 'data');
    $this->actingAs($admin, 'api')
        ->getJson("/api/account-team-candidates?exclude_team_id={$guest_team->id}")
        ->assertJsonCount(2, 'data');
});

test('any member can leave a team on their own', function () {
    $member = teamUserWithRole('staff');
    $team = AccountTeam::factory()->create();
    $team->members()->attach($member->id, ['is_team_owner' => true]);

    $this->actingAs($member, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$member->id}")
        ->assertOk()
        ->assertJsonPath('team.member_count', 0);
});

test('the team list reports whether the viewer is a member', function () {
    $member = teamUserWithRole('staff');
    $joined = AccountTeam::factory()->create(['name' => 'A team']);
    AccountTeam::factory()->create(['name' => 'B team']);
    $joined->members()->attach($member->id);

    $this->actingAs($member, 'api')
        ->getJson('/api/account-teams')
        ->assertJsonPath('data.0.is_member', true)
        ->assertJsonPath('data.1.is_member', false);
});

test('admins and team owners can upload and remove the team picture and cover', function () {
    Storage::fake(config('filesystems.app_disk'));
    $owner = teamUserWithRole('staff');
    $team = AccountTeam::factory()->create();
    $team->members()->attach($owner->id, ['is_team_owner' => true]);

    $this->actingAs($owner, 'api')
        ->post("/api/account-teams/{$team->id}/picture", ['file' => UploadedFile::fake()->image('team.png', 800, 800)], ['Accept' => 'application/json'])
        ->assertOk();
    $this->actingAs($owner, 'api')
        ->post("/api/account-teams/{$team->id}/cover", ['file' => UploadedFile::fake()->image('cover.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();

    $team->refresh();
    expect($team->picture_path)->not->toBeNull()->and($team->cover_path)->not->toBeNull();
    Storage::disk(config('filesystems.app_disk'))->assertExists($team->picture_path);

    $this->actingAs($owner, 'api')->deleteJson("/api/account-teams/{$team->id}/cover")->assertOk()->assertJsonPath('team.cover_url', null);
});

test('plain staff cannot change a team picture', function () {
    Storage::fake(config('filesystems.app_disk'));
    $staff = teamUserWithRole('staff');
    $team = AccountTeam::factory()->create();

    $this->actingAs($staff, 'api')
        ->post("/api/account-teams/{$team->id}/picture", ['file' => UploadedFile::fake()->image('team.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('a team can join boards and its members get access to them', function () {
    $admin = teamUserWithRole('admin');
    $member = teamUserWithRole('staff');
    $late_member = teamUserWithRole('staff');
    $team = AccountTeam::factory()->create();
    $team->members()->attach($member->id);
    $board = WorkspaceNavigationItem::factory()->create(['label' => 'Roadmap']);

    $this->actingAs($admin, 'api')
        ->getJson("/api/account-teams/{$team->id}/board-candidates?search=road")
        ->assertJsonPath('data.0.name', 'Roadmap');

    $this->actingAs($admin, 'api')
        ->postJson("/api/account-teams/{$team->id}/boards", ['board_ids' => [$board->id]])
        ->assertOk()
        ->assertJsonPath('team.board_count', 1);

    expect($board->collaborators()->whereKey($member->id)->exists())->toBeTrue();

    $this->actingAs($admin, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$late_member->id]])
        ->assertOk();

    expect($board->collaborators()->whereKey($late_member->id)->exists())->toBeTrue();

    $this->actingAs($admin, 'api')
        ->getJson("/api/account-teams/{$team->id}/boards")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Roadmap');

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/boards/{$board->id}")
        ->assertOk()
        ->assertJsonPath('team.board_count', 0);
});

test('a team with guests can only join shareable boards', function () {
    $admin = teamUserWithRole('admin');
    $team = AccountTeam::factory()->create(['allow_guests' => true]);
    $main_board = WorkspaceNavigationItem::factory()->create();
    $shareable_board = WorkspaceNavigationItem::factory()->create(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_SHAREABLE]);

    $this->actingAs($admin, 'api')
        ->postJson("/api/account-teams/{$team->id}/boards", ['board_ids' => [$main_board->id]])
        ->assertUnprocessable();

    $this->actingAs($admin, 'api')
        ->postJson("/api/account-teams/{$team->id}/boards", ['board_ids' => [$shareable_board->id]])
        ->assertOk();
});

test('plain staff cannot add boards to a team they do not own', function () {
    $staff = teamUserWithRole('staff');
    $team = AccountTeam::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create();

    $this->actingAs($staff, 'api')
        ->postJson("/api/account-teams/{$team->id}/boards", ['board_ids' => [$board->id]])
        ->assertForbidden();
});
