<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('a super admin can list roles with their permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin, 'api')->getJson('/api/admin/roles');

    $response->assertOk()->assertJsonCount(4, 'roles');
});

test('a super admin can assign a role to a user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $response = $this->actingAs($admin, 'api')
        ->postJson("/api/admin/roles/users/{$target->id}/assign", ['role' => 'staff']);

    $response->assertOk();
    expect($target->fresh()->hasRole('staff'))->toBeTrue();
});

test('a super admin can revoke a role from a user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $response = $this->actingAs($admin, 'api')
        ->postJson("/api/admin/roles/users/{$target->id}/revoke", ['role' => 'client']);

    $response->assertOk();
    expect($target->fresh()->hasRole('client'))->toBeFalse();
});

test('assigning an unknown role is rejected', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $target = User::factory()->create();

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/roles/users/{$target->id}/assign", ['role' => 'not-a-role'])
        ->assertStatus(422);
});

test('a non super admin cannot manage roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();

    $this->actingAs($admin, 'api')
        ->getJson('/api/admin/roles')
        ->assertForbidden();
});

test('a client cannot access admin role endpoints', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($client, 'api')
        ->getJson('/api/admin/roles')
        ->assertForbidden();
});
