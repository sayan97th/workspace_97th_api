<?php

use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;

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

test('an admin can update a user name and email', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $response = $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated-name@example.com',
        ]);

    $response->assertOk()
        ->assertJsonPath('user.first_name', 'Updated')
        ->assertJsonPath('user.last_name', 'Name')
        ->assertJsonPath('user.email', 'updated-name@example.com');

    expect($target->fresh())
        ->first_name->toBe('Updated')
        ->last_name->toBe('Name')
        ->email->toBe('updated-name@example.com');
});

test('a user cannot be updated with an email already used by someone else', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $taken = User::factory()->create();
    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}", ['email' => $taken->email])
        ->assertStatus(422);
});

test('an admin can delete a client account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/admin/users/{$target->id}")
        ->assertOk();

    expect(User::find($target->id))->toBeNull();
});

test('a user cannot delete their own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/admin/users/{$admin->id}")
        ->assertStatus(422);

    expect(User::find($admin->id))->not->toBeNull();
});

test('an admin cannot delete another admin or super admin account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $other_admin = User::factory()->create();
    $other_admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/admin/users/{$other_admin->id}")
        ->assertForbidden();

    expect(User::find($other_admin->id))->not->toBeNull();
});

test('a super admin can delete an admin account', function () {
    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($super_admin, 'api')
        ->deleteJson("/api/admin/users/{$admin->id}")
        ->assertOk();

    expect(User::find($admin->id))->toBeNull();
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

test('an admin can set a user password directly', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}/password", [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    expect(Hash::check('Str0ng!Passw0rd', $target->fresh()->password))->toBeTrue();
});

test('setting a user password requires confirmation', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}/password", ['password' => 'Str0ng!Passw0rd'])
        ->assertStatus(422);
});

test('a user cannot set their own password through the admin endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$admin->id}/password", [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertStatus(422);
});

test('an admin cannot set the password of another admin or super admin account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $other_admin = User::factory()->create();
    $other_admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$other_admin->id}/password", [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertForbidden();
});

test('an admin can send a password reset link to a user', function () {
    Bus::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create();
    $target->assignRole('client');

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/users/{$target->id}/send-password-reset-link")
        ->assertOk()
        ->assertJsonPath('message', "A password reset email has been sent to {$target->email}.");

    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === $target->email
        && $job->mailable instanceof PasswordResetMail
        && $job->mailable->user->is($target));
});

test('a user cannot send themselves a reset link through the admin endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/users/{$admin->id}/send-password-reset-link")
        ->assertStatus(422);
});

test('an admin cannot send a password reset link to another admin or super admin account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $other_admin = User::factory()->create();
    $other_admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->postJson("/api/admin/users/{$other_admin->id}/send-password-reset-link")
        ->assertForbidden();
});

test('an invalid sort_field is rejected', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')
        ->getJson('/api/admin/users?sort_field=password')
        ->assertStatus(422);
});

test('users can be sorted by name', function () {
    $staff = User::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Adams']);
    $staff->assignRole('staff');

    $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Baker']);
    $alice->assignRole('client');

    $response = $this->actingAs($staff, 'api')
        ->getJson('/api/admin/users?sort_field=name&sort_direction=asc')
        ->assertOk();

    $emails = collect($response->json('data'))->pluck('email');
    expect($emails->search($alice->email))->toBeLessThan($emails->search($staff->email));
});

test('users can be sorted by role', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');

    $response = $this->actingAs($super_admin, 'api')
        ->getJson('/api/admin/users?sort_field=role&sort_direction=asc')
        ->assertOk();

    $emails = collect($response->json('data'))->pluck('email');
    expect($emails->search($super_admin->email))->toBeLessThan($emails->search($client->email));
});

test('users can be sorted by department, with unassigned users still included', function () {
    $department = Department::factory()->create(['name' => 'Engineering']);

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $with_department = User::factory()->create(['department_id' => $department->id]);
    $with_department->assignRole('client');

    $response = $this->actingAs($staff, 'api')
        ->getJson('/api/admin/users?sort_field=department&sort_direction=asc')
        ->assertOk();

    $emails = collect($response->json('data'))->pluck('email');
    expect($emails)->toContain($staff->email, $with_department->email);
});
