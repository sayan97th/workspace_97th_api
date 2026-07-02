<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('guests are redirected to login when visiting horizon', function () {
    $response = $this->get('/horizon');

    $response->assertRedirect(route('login'));
});

test('a client cannot view the horizon dashboard', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $response = $this->actingAs($client)->get('/horizon/api/stats');

    $response->assertForbidden();
});

test('an admin can view the horizon dashboard', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/horizon/api/stats');

    $response->assertOk();
});

test('a super admin can view the horizon dashboard', function () {
    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');

    $response = $this->actingAs($super_admin)->get('/horizon/api/stats');

    $response->assertOk();
});
