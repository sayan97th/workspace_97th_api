<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function departmentUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('an admin can reserve seats and see usage on the department list', function () {
    $admin = departmentUser('admin');
    $department = Department::factory()->create(['seat_limit' => 1]);
    departmentUser('staff')->update(['department_id' => $department->id]);
    departmentUser('staff')->update(['department_id' => $department->id]);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/departments/{$department->id}", ['seat_limit' => 1])
        ->assertOk()
        ->assertJsonPath('department.reserved', 1);

    $this->actingAs($admin, 'api')
        ->getJson('/api/admin/departments')
        ->assertOk()
        ->assertJsonPath('data.0.assigned', 2)
        ->assertJsonPath('data.0.available', 0)
        ->assertJsonPath('data.0.over_by', 1)
        ->assertJsonPath('data.0.can_administer', true);
});

test('an admin can assign and remove department owners', function () {
    $admin = departmentUser('admin');
    $owner = departmentUser('staff');
    $department = Department::factory()->create();

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/departments/{$department->id}/owners", ['user_ids' => [$owner->id]])
        ->assertOk()
        ->assertJsonPath('department.owners.0.id', $owner->id);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/admin/departments/{$department->id}/owners/{$owner->id}")
        ->assertOk()
        ->assertJsonCount(0, 'department.owners');
});

test('only staff tier users can become department owners', function () {
    $admin = departmentUser('admin');
    $client = departmentUser('client');
    $department = Department::factory()->create();

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/departments/{$department->id}/owners", ['user_ids' => [$client->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_ids.0');
});

test('a staff user cannot assign department owners', function () {
    $staff = departmentUser('staff');
    $department = Department::factory()->create();
    $department->owners()->attach($staff->id);

    $this->actingAs($staff, 'api')
        ->postJson("/api/admin/departments/{$department->id}/owners", ['user_ids' => [$staff->id]])
        ->assertForbidden();
});

test('an admin can move users between departments', function () {
    $admin = departmentUser('admin');
    $from = Department::factory()->create();
    $to = Department::factory()->create();
    $member = departmentUser('staff');
    $member->update(['department_id' => $from->id]);

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/departments/{$to->id}/members", ['user_ids' => [$member->id]])
        ->assertOk()
        ->assertJsonPath('department.assigned', 1);

    expect($member->fresh()->department_id)->toBe($to->id);
});

test('a department owner can assign unassigned users and remove members of their department', function () {
    $owner = departmentUser('staff');
    $department = Department::factory()->create();
    $department->owners()->attach($owner->id);
    $unassigned = departmentUser('client');

    $this->actingAs($owner, 'api')
        ->postJson("/api/admin/departments/{$department->id}/members", ['user_ids' => [$unassigned->id]])
        ->assertOk()
        ->assertJsonPath('department.can_manage_members', true)
        ->assertJsonPath('department.can_administer', false);

    expect($unassigned->fresh()->department_id)->toBe($department->id);

    $this->actingAs($owner, 'api')
        ->deleteJson("/api/admin/departments/{$department->id}/members/{$unassigned->id}")
        ->assertOk()
        ->assertJsonPath('department.assigned', 0);

    expect($unassigned->fresh()->department_id)->toBeNull();
});

test('a department owner cannot take users out of another department', function () {
    $owner = departmentUser('staff');
    $department = Department::factory()->create();
    $department->owners()->attach($owner->id);
    $other = Department::factory()->create();
    $taken = departmentUser('staff');
    $taken->update(['department_id' => $other->id]);

    $this->actingAs($owner, 'api')
        ->postJson("/api/admin/departments/{$department->id}/members", ['user_ids' => [$taken->id]])
        ->assertForbidden();

    expect($taken->fresh()->department_id)->toBe($other->id);
});

test('a staff user who does not own the department cannot manage its members', function () {
    $staff = departmentUser('staff');
    $department = Department::factory()->create();
    $target = departmentUser('client');

    $this->actingAs($staff, 'api')
        ->postJson("/api/admin/departments/{$department->id}/members", ['user_ids' => [$target->id]])
        ->assertForbidden();
});

test('a client cannot reach the department endpoints', function () {
    $client = departmentUser('client');

    $this->actingAs($client, 'api')
        ->getJson('/api/admin/departments')
        ->assertForbidden();
});
