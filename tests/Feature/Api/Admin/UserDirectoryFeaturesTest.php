<?php

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Models\UserProfileField;
use App\Models\UserSession;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function sessionFor(User $user, string $last_used_at, array $attributes = []): UserSession
{
    return UserSession::create([
        'user_id' => $user->id,
        'jti' => (string) Str::uuid(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'last_used_at' => $last_used_at,
        'expires_at' => now()->addDay(),
        ...$attributes,
    ]);
}

test('the users list accepts several roles and statuses at once', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $client = User::factory()->create(['is_active' => false]);
    $client->assignRole('client');
    $deleted = User::factory()->create();
    $deleted->assignRole('client');
    $deleted->delete();

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/users?role=staff,client&account_status=disabled,deleted')
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toContain($client->id, $deleted->id)->not->toContain($staff->id, $this->admin->id);
});

test('the users list filters by department including unassigned', function () {
    $department = Department::factory()->create();
    $in_department = User::factory()->create(['department_id' => $department->id]);
    $unassigned = User::factory()->create();

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson("/api/admin/users?department={$department->id}")
        ->json('data.*.id');
    expect($ids)->toBe([$in_department->id]);

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson("/api/admin/users?department={$department->id},unassigned&per_page=100")
        ->json('data.*.id');
    expect($ids)->toContain($in_department->id, $unassigned->id);
});

test('the users list returns and filters by last active', function () {
    $recent = User::factory()->create();
    sessionFor($recent, now()->subDay()->toDateTimeString());
    $stale = User::factory()->create();
    sessionFor($stale, now()->subDays(60)->toDateTimeString());
    $never = User::factory()->create();

    $response = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/users?last_active_from='.now()->subDays(7)->toDateString())
        ->assertOk();
    expect($response->json('data.*.id'))->toBe([$recent->id]);
    expect($response->json('data.0.last_active_at'))->not->toBeNull();

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/users?last_active_to='.now()->subDays(30)->toDateString())
        ->json('data.*.id');
    expect($ids)->toBe([$stale->id]);

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/users?last_active_never=1&per_page=100')
        ->json('data.*.id');
    expect($ids)->toContain($never->id, $this->admin->id)->not->toContain($recent->id, $stale->id);
});

test('the users list sorts by last active with never active users last', function () {
    $older = User::factory()->create();
    sessionFor($older, now()->subDays(10)->toDateTimeString());
    $newer = User::factory()->create();
    sessionFor($newer, now()->subDay()->toDateTimeString());

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/users?sort_field=last_active&sort_direction=desc')
        ->json('data.*.id');

    expect(array_slice($ids, 0, 2))->toBe([$newer->id, $older->id]);
    expect(end($ids))->toBe($this->admin->id);
});

test('the users list filters by custom profile field values', function () {
    $field = UserProfileField::create(['name' => 'Office', 'type' => 'dropdown', 'options' => [['id' => 'ny', 'label' => 'New York'], ['id' => 'la', 'label' => 'Los Angeles']]]);
    $number = UserProfileField::create(['name' => 'Seniority', 'type' => 'number']);
    $in_ny = User::factory()->create();
    $in_la = User::factory()->create();
    $in_ny->profileFieldValues()->create(['field_id' => $field->id, 'value' => 'ny']);
    $in_ny->profileFieldValues()->create(['field_id' => $number->id, 'value' => '8']);
    $in_la->profileFieldValues()->create(['field_id' => $field->id, 'value' => 'la']);
    $in_la->profileFieldValues()->create(['field_id' => $number->id, 'value' => '2']);

    $response = $this->actingAs($this->admin, 'api')
        ->getJson("/api/admin/users?fields[{$field->id}][in]=ny")
        ->assertOk();
    expect($response->json('data.*.id'))->toBe([$in_ny->id]);
    expect($response->json("data.0.profile_fields.{$field->id}"))->toBe('ny');

    $ids = $this->actingAs($this->admin, 'api')
        ->getJson("/api/admin/users?fields[{$number->id}][min]=5")
        ->json('data.*.id');
    expect($ids)->toBe([$in_ny->id]);
});

test('an admin can bulk move users to a department and skips staff they cannot manage', function () {
    $department = Department::factory()->create();
    $clients = User::factory()->count(2)->create()->each(fn (User $user) => $user->assignRole('client'));
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $response = $this->actingAs($this->admin, 'api')->postJson('/api/admin/users/bulk', [
        'action' => 'set_department',
        'department_id' => $department->id,
        'user_ids' => [...$clients->pluck('id'), $staff->id, $this->admin->id],
    ]);

    $response->assertOk()->assertJsonPath('updated_count', 2)->assertJsonPath('skipped_count', 2);
    expect($clients->first()->fresh()->department_id)->toBe($department->id);
    expect($staff->fresh()->department_id)->toBeNull();
    expect(AuditLog::where('event', 'user.bulk_set_department')->exists())->toBeTrue();
});

test('only a super admin can bulk change roles', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/users/bulk', ['action' => 'set_role', 'role' => 'staff', 'user_ids' => [$client->id]])
        ->assertForbidden();

    $super_admin = User::factory()->create();
    $super_admin->assignRole('super_admin');

    $this->actingAs($super_admin, 'api')
        ->postJson('/api/admin/users/bulk', ['action' => 'set_role', 'role' => 'staff', 'user_ids' => [$client->id]])
        ->assertOk()
        ->assertJsonPath('updated_count', 1);

    expect($client->fresh()->roles->pluck('name')->all())->toBe(['staff']);
});

test('an admin can bulk deactivate users', function () {
    $clients = User::factory()->count(2)->create()->each(fn (User $user) => $user->assignRole('client'));

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/users/bulk', ['action' => 'deactivate', 'user_ids' => $clients->pluck('id')->all()])
        ->assertOk()
        ->assertJsonPath('updated_count', 2);

    expect($clients->every(fn (User $user) => ! $user->fresh()->is_active))->toBeTrue();
});

test('an admin can export the filtered users as csv', function () {
    $field = UserProfileField::create(['name' => 'Office', 'type' => 'text']);
    $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $user->profileFieldValues()->create(['field_id' => $field->id, 'value' => 'London']);

    $response = $this->actingAs($this->admin, 'api')->get("/api/admin/users/export?ids={$user->id}");

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Office')->toContain('Ada Lovelace')->toContain('London')
        ->not->toContain($this->admin->email);
});

test('staff cannot export or bulk edit users', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')->get('/api/admin/users/export')->assertForbidden();
    $this->actingAs($staff, 'api')->postJson('/api/admin/users/bulk', ['action' => 'deactivate', 'user_ids' => [1]])->assertForbidden();
});

test('the user details endpoint returns boards, sessions and recent activity', function () {
    $user = User::factory()->create();
    WorkspaceNavigationItem::factory()->create(['owner_id' => $user->id, 'label' => 'Roadmap']);
    sessionFor($user, now()->toDateTimeString());
    AuditLog::create(['user_id' => $this->admin->id, 'event' => 'user.deactivated', 'description' => 'Deactivated', 'metadata' => ['target_user_id' => $user->id]]);

    $this->actingAs($this->admin, 'api')
        ->getJson("/api/admin/users/{$user->id}/details")
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('owned_boards.total', 1)
        ->assertJsonPath('owned_boards.data.0.label', 'Roadmap')
        ->assertJsonCount(1, 'sessions')
        ->assertJsonPath('recent_activity.0.event', 'user.deactivated')
        ->assertJsonStructure(['teams', 'workspaces', 'stats' => ['items_created', 'updates_posted', 'boards_owned']]);
});

test('an admin can set and clear a user profile field value with type validation', function () {
    $field = UserProfileField::create(['name' => 'Start date', 'type' => 'date']);
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'api')
        ->putJson("/api/admin/users/{$user->id}/profile-fields", ['values' => [(string) $field->id => 'not a date']])
        ->assertUnprocessable();

    $this->actingAs($this->admin, 'api')
        ->putJson("/api/admin/users/{$user->id}/profile-fields", ['values' => [(string) $field->id => '2026-01-15']])
        ->assertOk()
        ->assertJsonPath("user.profile_fields.{$field->id}", '2026-01-15');

    $this->actingAs($this->admin, 'api')
        ->putJson("/api/admin/users/{$user->id}/profile-fields", ['values' => [(string) $field->id => null]])
        ->assertOk();

    expect($user->profileFieldValues()->count())->toBe(0);
});
