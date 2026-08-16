<?php

use App\Jobs\SendEmailJob;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('a user can register and receives a token with the client role', function () {
    Bus::fake();

    $response = $this->postJson('/api/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonPath('user.roles.0.name', 'client')
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($user->hasRole('client'))->toBeTrue();

    Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipientEmail === 'jane@example.com'
        && $job->mailable instanceof WelcomeMail
        && $job->mailable->user->is($user));
});

test('registering with a pending workspace invitation auto-joins the workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    $invitation = WorkspaceInvitation::factory()->for($workspace)->create([
        'email' => 'jane@example.com',
        'role' => 'member',
        'invited_by' => $owner->id,
    ]);

    $response = $this->postJson('/api/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertOk();

    $user = User::where('email', 'jane@example.com')->firstOrFail();

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('registering does not join a workspace from an expired invitation', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    WorkspaceInvitation::factory()->for($workspace)->expired()->create([
        'email' => 'jane@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->postJson('/api/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertOk();

    $user = User::where('email', 'jane@example.com')->firstOrFail();

    $this->assertDatabaseMissing('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
});

test('a user can log in with valid credentials', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $user->assignRole('client');

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['access_token']);
});

test('login fails with invalid credentials', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');
});

test('a disabled account cannot log in', function () {
    $user = User::factory()->create(['password' => 'password123', 'is_active' => false]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertStatus(403)->assertJsonPath('code', 'account_disabled');
});

test('an authenticated user can fetch their own profile via me', function () {
    $user = User::factory()->create();
    $user->assignRole('client');

    $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.roles.0.name', 'client');
});

test('me requires authentication', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

test('a user can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Successfully logged out.');
});
