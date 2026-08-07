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
    $staff = staffUser();
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
    $staff = staffUser();

    $this->actingAs($staff, 'api')
        ->postJson('/api/account-teams', [])
        ->assertStatus(422);
});

test('a team can be renamed', function () {
    $staff = staffUser();
    $team = AccountTeam::factory()->create(['name' => 'Old name']);

    $this->actingAs($staff, 'api')
        ->patchJson("/api/account-teams/{$team->id}", ['name' => 'New name'])
        ->assertOk()
        ->assertJsonPath('team.name', 'New name');

    $this->assertDatabaseHas('account_teams', ['id' => $team->id, 'name' => 'New name']);
});

test('a team can be deleted', function () {
    $staff = staffUser();
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
    $staff = staffUser();
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
    $staff = staffUser();
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
