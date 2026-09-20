<?php

use App\Models\AccountTeam;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function staffUser(array $overrides = []): User
{
    $user = User::factory()->create($overrides);
    $user->assignRole('staff');

    return $user;
}

function adminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

test('a client cannot access the teams directory', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($client, 'api')
        ->getJson('/api/account-teams')
        ->assertForbidden();
});

test('staff can list teams with their member counts', function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create();
    $team->members()->attach(staffUser()->id);
    $team->members()->attach(staffUser()->id);

    $this->actingAs($staff, 'api')
        ->getJson('/api/account-teams')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.member_count', 2);
});

test('teams can be searched by name', function () {
    $staff = staffUser();
    AccountTeam::factory()->create(['name' => 'Account Directors']);
    AccountTeam::factory()->create(['name' => 'Department Heads']);

    $this->actingAs($staff, 'api')
        ->getJson('/api/account-teams?search=director')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Account Directors');
});

test('a team can be created with an initial roster in one request', function () {
    $staff = adminUser();
    $members = User::factory()->count(3)->create();
    foreach ($members as $member) {
        $member->assignRole('staff');
    }

    $response = $this->actingAs($staff, 'api')->postJson('/api/account-teams', [
        'name' => 'Account Directors',
        'member_ids' => $members->pluck('id'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('team.name', 'Account Directors')
        ->assertJsonPath('team.member_count', 3);

    $this->assertDatabaseHas('account_teams', ['name' => 'Account Directors']);
    foreach ($members as $member) {
        $this->assertDatabaseHas('account_team_user', ['user_id' => $member->id]);
    }
});

test('creating a team requires a name', function () {
    $staff = adminUser();

    $this->actingAs($staff, 'api')
        ->postJson('/api/account-teams', [])
        ->assertStatus(422);
});

test('a team can be renamed', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create(['name' => 'Old name']);

    $this->actingAs($staff, 'api')
        ->patchJson("/api/account-teams/{$team->id}", ['name' => 'New name'])
        ->assertOk()
        ->assertJsonPath('team.name', 'New name');

    $this->assertDatabaseHas('account_teams', ['id' => $team->id, 'name' => 'New name']);
});

test('a team can be deleted', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();

    $this->actingAs($staff, 'api')
        ->deleteJson("/api/account-teams/{$team->id}")
        ->assertOk();

    $this->assertSoftDeleted('account_teams', ['id' => $team->id]);
});

test("a team's roster is searched and paginated server-side", function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create();
    $matching = staffUser(['first_name' => 'Zelda', 'last_name' => 'Zephyr']);
    $team->members()->attach($matching->id);
    $team->members()->attach(staffUser(['first_name' => 'Someone', 'last_name' => 'Else'])->id);

    $this->actingAs($staff, 'api')
        ->getJson("/api/account-teams/{$team->id}/members?search=Zelda&per_page=5")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $matching->id)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('current_page', 1);
});

test("a team's roster can be fully replaced", function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();
    $original_member = staffUser();
    $team->members()->attach($original_member->id);
    $replacement_member = staffUser();

    $this->actingAs($staff, 'api')
        ->putJson("/api/account-teams/{$team->id}/members", [
            'member_ids' => [$replacement_member->id],
        ])
        ->assertOk();

    $this->assertDatabaseMissing('account_team_user', ['account_team_id' => $team->id, 'user_id' => $original_member->id]);
    $this->assertDatabaseHas('account_team_user', ['account_team_id' => $team->id, 'user_id' => $replacement_member->id]);
});

test('a client cannot be added as a team member', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($staff, 'api')
        ->putJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$client->id]])
        ->assertStatus(422);
});

test('"all members" dedupes staff who belong to more than one team', function () {
    $staff = staffUser();
    $shared_member = staffUser();
    $team_one = AccountTeam::factory()->create();
    $team_two = AccountTeam::factory()->create();
    $team_one->members()->attach($shared_member->id);
    $team_two->members()->attach($shared_member->id);

    $this->actingAs($staff, 'api')
        ->getJson('/api/account-team-members')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('team_count', 2);
});

test('"all members" excludes staff who belong to no team', function () {
    $staff = staffUser();
    staffUser(); // never attached to a team

    $this->actingAs($staff, 'api')
        ->getJson('/api/account-team-members')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the candidate directory excludes client accounts', function () {
    $staff = staffUser();
    $client = User::factory()->create();
    $client->assignRole('client');

    $response = $this->actingAs($staff, 'api')->getJson('/api/account-team-candidates');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain((string) $client->id);
    expect($ids)->toContain((string) $staff->id);
});

test('members can be added to a team without disturbing the existing roster', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();
    $existing_member = staffUser();
    $team->members()->attach($existing_member->id);
    $new_member = staffUser();

    $this->actingAs($staff, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$new_member->id]])
        ->assertOk()
        ->assertJsonPath('team.member_count', 2);

    $this->assertDatabaseHas('account_team_user', ['account_team_id' => $team->id, 'user_id' => $existing_member->id]);
    $this->assertDatabaseHas('account_team_user', ['account_team_id' => $team->id, 'user_id' => $new_member->id]);
});

test('adding members requires at least one id', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();

    $this->actingAs($staff, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => []])
        ->assertStatus(422);
});

test('a client cannot be added as a team member via the add endpoint', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($staff, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$client->id]])
        ->assertStatus(422);
});

test('a single member can be removed from a team', function () {
    $staff = adminUser();
    $team = AccountTeam::factory()->create();
    $member_to_remove = staffUser();
    $remaining_member = staffUser();
    $team->members()->attach([$member_to_remove->id, $remaining_member->id]);

    $this->actingAs($staff, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$member_to_remove->id}")
        ->assertOk()
        ->assertJsonPath('team.member_count', 1);

    $this->assertDatabaseMissing('account_team_user', ['account_team_id' => $team->id, 'user_id' => $member_to_remove->id]);
    $this->assertDatabaseHas('account_team_user', ['account_team_id' => $team->id, 'user_id' => $remaining_member->id]);
});

test("the candidate directory can exclude a team's current members", function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create();
    $existing_member = staffUser();
    $team->members()->attach($existing_member->id);

    $response = $this->actingAs($staff, 'api')
        ->getJson("/api/account-team-candidates?exclude_team_id={$team->id}");

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain((string) $existing_member->id);
    expect($ids)->toContain((string) $staff->id);
});

test('the account owner is flagged in the roster', function () {
    $owner = staffUser();
    $owner->assignRole('super_admin');
    $team = AccountTeam::factory()->create();
    $team->members()->attach($owner->id);

    $this->actingAs($owner, 'api')
        ->getJson("/api/account-teams/{$team->id}/members")
        ->assertOk()
        ->assertJsonPath('data.0.is_owner', true);
});

test('staff can read teams but cannot create, rename or delete them', function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create();

    $this->actingAs($staff, 'api')->getJson('/api/account-teams')->assertOk();
    $this->actingAs($staff, 'api')->postJson('/api/account-teams', ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($staff, 'api')->patchJson("/api/account-teams/{$team->id}", ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($staff, 'api')->deleteJson("/api/account-teams/{$team->id}")->assertForbidden();
    $this->actingAs($staff, 'api')
        ->putJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$staff->id]])
        ->assertForbidden();

    $this->assertDatabaseMissing('account_teams', ['name' => 'Nope']);
});

test('the account owner can create a team', function () {
    $owner = staffUser();
    $owner->assignRole('super_admin');

    $this->actingAs($owner, 'api')
        ->postJson('/api/account-teams', ['name' => 'Owner team'])
        ->assertCreated();
});

test('regular staff cannot add or remove members of a team they do not own', function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create();
    $member = staffUser();
    $team->members()->attach($member->id);

    $this->actingAs($staff, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$staff->id]])
        ->assertForbidden();
    $this->actingAs($staff, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$member->id}")
        ->assertForbidden();

    $this->assertDatabaseMissing('account_team_user', ['account_team_id' => $team->id, 'user_id' => $staff->id]);
    $this->assertDatabaseHas('account_team_user', ['account_team_id' => $team->id, 'user_id' => $member->id]);
});

test('an admin can make a member a team owner and take it away again', function () {
    $admin = adminUser();
    $team = AccountTeam::factory()->create();
    $member = staffUser();
    $team->members()->attach($member->id);

    $this->actingAs($admin, 'api')
        ->putJson("/api/account-teams/{$team->id}/owners/{$member->id}")
        ->assertOk()
        ->assertJsonPath('team.owners.0.id', (string) $member->id);

    $this->assertDatabaseHas('account_team_user', ['user_id' => $member->id, 'is_team_owner' => true]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/owners/{$member->id}")
        ->assertOk()
        ->assertJsonCount(0, 'team.owners')
        ->assertJsonPath('team.member_count', 1);

    $this->assertDatabaseHas('account_team_user', ['user_id' => $member->id, 'is_team_owner' => false]);
});

test('making someone an owner also puts them on the roster', function () {
    $admin = adminUser();
    $team = AccountTeam::factory()->create();
    $newcomer = staffUser();

    $this->actingAs($admin, 'api')
        ->putJson("/api/account-teams/{$team->id}/owners/{$newcomer->id}")
        ->assertOk()
        ->assertJsonPath('team.member_count', 1);

    $this->assertDatabaseHas('account_team_user', [
        'account_team_id' => $team->id,
        'user_id' => $newcomer->id,
        'is_team_owner' => true,
    ]);
});

test('a client cannot be made a team owner', function () {
    $admin = adminUser();
    $team = AccountTeam::factory()->create();
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($admin, 'api')
        ->putJson("/api/account-teams/{$team->id}/owners/{$client->id}")
        ->assertStatus(422);
});

test('a team owner cannot assign or remove owners', function () {
    $team = AccountTeam::factory()->create();
    $team_owner = staffUser();
    $team->members()->attach($team_owner->id, ['is_team_owner' => true]);
    $other = staffUser();
    $team->members()->attach($other->id);

    $this->actingAs($team_owner, 'api')
        ->putJson("/api/account-teams/{$team->id}/owners/{$other->id}")
        ->assertForbidden();
    $this->actingAs($team_owner, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/owners/{$team_owner->id}")
        ->assertForbidden();
});

test('a team owner can add and remove members of their own team', function () {
    $team = AccountTeam::factory()->create();
    $team_owner = staffUser();
    $team->members()->attach($team_owner->id, ['is_team_owner' => true]);
    $member = staffUser();
    $newcomer = staffUser();
    $team->members()->attach($member->id);

    $this->actingAs($team_owner, 'api')
        ->postJson("/api/account-teams/{$team->id}/members", ['member_ids' => [$newcomer->id]])
        ->assertOk()
        ->assertJsonPath('team.member_count', 3)
        ->assertJsonPath('team.can_manage_members', true)
        ->assertJsonPath('team.can_manage', false);

    $this->actingAs($team_owner, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$member->id}")
        ->assertOk()
        ->assertJsonPath('team.member_count', 2);
});

test("a team owner cannot manage another team's roster", function () {
    $own_team = AccountTeam::factory()->create();
    $other_team = AccountTeam::factory()->create();
    $team_owner = staffUser();
    $own_team->members()->attach($team_owner->id, ['is_team_owner' => true]);

    $this->actingAs($team_owner, 'api')
        ->postJson("/api/account-teams/{$other_team->id}/members", ['member_ids' => [$team_owner->id]])
        ->assertForbidden();
});

test('a team owner cannot remove another team owner but an admin can', function () {
    $team = AccountTeam::factory()->create();
    $first_owner = staffUser();
    $second_owner = staffUser();
    $team->members()->attach([
        $first_owner->id => ['is_team_owner' => true],
        $second_owner->id => ['is_team_owner' => true],
    ]);

    $this->actingAs($first_owner, 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$second_owner->id}")
        ->assertForbidden();

    $this->actingAs(adminUser(), 'api')
        ->deleteJson("/api/account-teams/{$team->id}/members/{$second_owner->id}")
        ->assertOk();

    $this->assertDatabaseMissing('account_team_user', ['account_team_id' => $team->id, 'user_id' => $second_owner->id]);
});

test('the roster and the team list expose ownership and what the viewer may do', function () {
    $team = AccountTeam::factory()->create();
    $team_owner = staffUser();
    $team->members()->attach($team_owner->id, ['is_team_owner' => true]);
    $viewer = staffUser();

    $this->actingAs($viewer, 'api')
        ->getJson("/api/account-teams/{$team->id}/members")
        ->assertOk()
        ->assertJsonPath('data.0.is_team_owner', true);

    $this->actingAs($viewer, 'api')
        ->getJson('/api/account-teams')
        ->assertOk()
        ->assertJsonPath('data.0.can_manage', false)
        ->assertJsonPath('data.0.can_manage_members', false)
        ->assertJsonPath('data.0.owners.0.id', (string) $team_owner->id);

    $this->actingAs(adminUser(), 'api')
        ->getJson('/api/account-teams')
        ->assertJsonPath('data.0.can_manage', true)
        ->assertJsonPath('data.0.can_manage_members', true);
});
