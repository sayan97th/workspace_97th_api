<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('staff can list users', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    User::factory()->count(3)->create()->each(fn (User $u) => $u->assignRole('client'));

    $response = $this->actingAs($staff, 'api')->getJson('/api/admin/users');

    $response->assertOk()->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
});

test('a client cannot list users', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($client, 'api')
        ->getJson('/api/admin/users')
        ->assertForbidden();
});

test('an admin can update a user phone number', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $response = $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}", ['phone' => '+1 555-0100']);

    $response->assertOk()->assertJsonPath('user.phone', '+1 555-0100');
    expect($target->fresh()->phone)->toBe('+1 555-0100');
});

test('an admin can ban and unban a client account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}/ban")
        ->assertOk();

    expect($target->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}/unban")
        ->assertOk();

    expect($target->fresh()->is_active)->toBeTrue();
});

test('a user cannot ban their own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$admin->id}/ban")
        ->assertStatus(422);

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('an admin cannot ban another admin or super admin account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $other_admin = User::factory()->create();
    $other_admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$other_admin->id}/ban")
        ->assertForbidden();

    expect($other_admin->fresh()->is_active)->toBeTrue();
});

test('a super admin can ban an admin account', function () {
    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($super_admin, 'api')
        ->patchJson("/api/admin/users/{$admin->id}/ban")
        ->assertOk();

    expect($admin->fresh()->is_active)->toBeFalse();
});
