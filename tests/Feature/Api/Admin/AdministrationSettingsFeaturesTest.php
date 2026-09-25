<?php

use App\Models\AccountSetting;
use App\Models\BoardItem;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\UserProfileField;
use App\Models\UserSession;
use App\Models\WorkspaceNavigationItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('invitations are listed by status and can be resent or canceled', function () {
    Bus::fake();

    $pending = StaffInvitation::create(['email' => 'pending@example.com', 'role' => 'staff', 'invited_by' => $this->admin->id, 'expires_at' => now()->addDays(3)]);
    $expired = StaffInvitation::create(['email' => 'expired@example.com', 'role' => 'client', 'invited_by' => $this->admin->id, 'expires_at' => now()->subDay()]);

    $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/invitations')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'pending@example.com')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('counts.pending', 1)
        ->assertJsonPath('counts.expired', 1);

    $this->actingAs($this->admin, 'api')
        ->postJson("/api/admin/invitations/{$expired->id}/resend")
        ->assertOk()
        ->assertJsonPath('invitation.status', 'pending');
    expect($expired->fresh()->expires_at->isFuture())->toBeTrue();

    $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/invitations/{$pending->id}")->assertOk();
    expect(StaffInvitation::find($pending->id))->toBeNull();
});

test('admins manage profile fields and removing a dropdown option clears its values', function () {
    $response = $this->actingAs($this->admin, 'api')->postJson('/api/admin/profile-fields', [
        'name' => 'Office',
        'type' => 'dropdown',
        'options' => [['id' => 'ny', 'label' => 'New York'], ['id' => 'la', 'label' => 'Los Angeles']],
    ])->assertCreated();

    $field = UserProfileField::findOrFail($response->json('field.id'));
    $user = User::factory()->create();
    $user->profileFieldValues()->create(['field_id' => $field->id, 'value' => 'la']);

    $this->actingAs($this->admin, 'api')
        ->patchJson("/api/admin/profile-fields/{$field->id}", ['options' => [['id' => 'ny', 'label' => 'NYC']]])
        ->assertOk()
        ->assertJsonPath('field.options.0.label', 'NYC');

    expect($user->profileFieldValues()->count())->toBe(0);

    $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/profile-fields/{$field->id}")->assertOk();
    expect(UserProfileField::count())->toBe(0);
});

test('staff can read profile fields but not create them', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')->getJson('/api/admin/profile-fields')->assertOk();
    $this->actingAs($staff, 'api')->postJson('/api/admin/profile-fields', ['name' => 'X', 'type' => 'text'])->assertForbidden();
});

test('usage stats count active users, new boards and the daily trend', function () {
    UserSession::create([
        'user_id' => $this->admin->id,
        'jti' => (string) Str::uuid(),
        'last_used_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    WorkspaceNavigationItem::factory()->create();

    $response = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/usage?from='.now()->subDays(6)->toDateString().'&to='.now()->toDateString())
        ->assertOk();

    expect($response->json('kpis.active_users'))->toBe(1);
    expect($response->json('kpis.boards_created'))->toBe(1);
    expect($response->json('daily_active_users'))->toHaveCount(7);
    expect(collect($response->json('daily_active_users'))->last()['active_users'])->toBe(1);
    expect($response->json('top_users.0.id'))->toBe($this->admin->id);
});

test('the content directory lists, filters and sorts boards by items', function () {
    $small = WorkspaceNavigationItem::factory()->create(['label' => 'Small board']);
    $big = WorkspaceNavigationItem::factory()->create(['label' => 'Big board', 'owner_id' => $this->admin->id]);
    BoardItem::factory()->count(3)->create(['board_id' => $big->id]);

    $response = $this->actingAs($this->admin, 'api')
        ->getJson('/api/admin/content?sort_field=items_count&sort_direction=desc')
        ->assertOk();
    expect($response->json('data.0.id'))->toBe($big->id);
    expect($response->json('data.0.items_count'))->toBe(3);
    expect($response->json('data.0.last_activity_at'))->not->toBeNull();

    $ids = $this->actingAs($this->admin, 'api')->getJson("/api/admin/content?owner=none&workspace={$small->workspace_id},{$big->workspace_id}")->json('data.*.id');
    expect($ids)->toContain($small->id)->not->toContain($big->id);

    $ids = $this->actingAs($this->admin, 'api')->getJson('/api/admin/content?items_min=1')->json('data.*.id');
    expect($ids)->toBe([$big->id]);
});

test('tidy up finds inactive boards and archives them in bulk', function () {
    $stale = WorkspaceNavigationItem::factory()->create();
    $fresh = WorkspaceNavigationItem::factory()->create();
    DB::table('workspace_navigation_items')->where('id', $stale->id)->update(['updated_at' => now()->subDays(120)]);

    $ids = $this->actingAs($this->admin, 'api')->getJson('/api/admin/content?inactive_days=90')->json('data.*.id');
    expect($ids)->toContain($stale->id)->not->toContain($fresh->id);

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/content/archive', ['board_ids' => [$stale->id, $fresh->id]])
        ->assertOk()
        ->assertJsonPath('updated_count', 2);
    expect($stale->fresh()->is_archived)->toBeTrue();

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/content/unarchive', ['board_ids' => [$stale->id]])
        ->assertOk()
        ->assertJsonPath('updated_count', 1);
    expect($stale->fresh()->is_archived)->toBeFalse();
});

test('boards can be reassigned in bulk but not to a deactivated user', function () {
    $board = WorkspaceNavigationItem::factory()->create();
    $inactive = User::factory()->create(['is_active' => false]);

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/content/reassign', ['board_ids' => [$board->id], 'owner_id' => $inactive->id])
        ->assertUnprocessable();

    $this->actingAs($this->admin, 'api')
        ->postJson('/api/admin/content/reassign', ['board_ids' => [$board->id], 'owner_id' => $this->admin->id])
        ->assertOk();
    expect($board->fresh()->owner_id)->toBe($this->admin->id);
});

test('the content directory exports csv', function () {
    WorkspaceNavigationItem::factory()->create(['label' => 'Quarterly plan']);

    $csv = $this->actingAs($this->admin, 'api')->get('/api/admin/content/export')->assertOk()->streamedContent();

    expect($csv)->toContain('Last activity')->toContain('Quarterly plan');
});

test('account permissions block a role from exporting and creating workspaces', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/account-settings/permissions', ['permissions' => ['client' => ['create_workspaces' => false]]])
        ->assertOk()
        ->assertJsonPath('matrix.client.create_workspaces', false)
        ->assertJsonPath('matrix.staff.create_workspaces', true);

    $this->actingAs($client, 'api')
        ->postJson('/api/workspaces', ['name' => 'Blocked'])
        ->assertForbidden();

    $this->actingAs($client, 'api')
        ->getJson('/api/account-permissions/me')
        ->assertOk()
        ->assertJsonPath('permissions.create_workspaces', false)
        ->assertJsonPath('permissions.export_data', true);

    $this->actingAs($this->admin, 'api')
        ->getJson('/api/account-permissions/me')
        ->assertJsonPath('permissions.create_workspaces', true);
});

test('staff cannot change account permissions', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')->getJson('/api/admin/account-settings/permissions')->assertOk();
    $this->actingAs($staff, 'api')
        ->patchJson('/api/admin/account-settings/permissions', ['permissions' => ['staff' => ['export_data' => false]]])
        ->assertForbidden();
});

test('account defaults are saved and applied to new users only', function () {
    $existing = User::factory()->create(['language' => 'en']);

    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/account-settings/defaults', [
            'default_timezone' => 'America/New_York',
            'default_language' => 'es',
            'default_time_format' => '24',
            'default_first_day_of_week' => 'monday',
        ])
        ->assertOk()
        ->assertJsonPath('account_settings.default_language', 'es');

    $new_user = User::factory()->create();

    expect($new_user->fresh()->timezone)->toBe('America/New_York');
    expect($new_user->fresh()->language)->toBe('es');
    expect($new_user->fresh()->time_format)->toBe('24');
    expect($existing->fresh()->language)->toBe('en');
    expect(AccountSetting::current()->default_first_day_of_week)->toBe('monday');
});

test('sessions can be filtered by device type and a user can be logged out everywhere', function () {
    $user = User::factory()->create();
    $mobile_agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    $desktop_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    foreach ([$mobile_agent, $desktop_agent] as $agent) {
        UserSession::create(['user_id' => $user->id, 'jti' => (string) Str::uuid(), 'user_agent' => $agent, 'last_used_at' => now(), 'expires_at' => now()->addDay()]);
    }

    $response = $this->actingAs($this->admin, 'api')->getJson('/api/admin/sessions?device_type=mobile')->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.device_type'))->toBe('mobile');

    $response = $this->actingAs($this->admin, 'api')->getJson('/api/admin/sessions?browser=chrome&user='.$user->id)->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.device_type'))->toBe('desktop');

    $this->actingAs($this->admin, 'api')
        ->deleteJson("/api/admin/sessions/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('revoked_count', 2);
});
