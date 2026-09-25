<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Facades\DB;

/**
 * Board permissions (Edit everything / Edit content / Assigned items only /
 * View only) and column permissions (restrict view, restrict edit).
 */
function createPermissionBoard(string $edit_permission = 'everything'): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $board = WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'created_by_id' => $owner->id,
        'edit_permission' => $edit_permission,
    ]);
    $group = BoardGroup::factory()->create(['board_id' => $board->id]);
    $people = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $group->board_view_id,
        'type' => BoardColumn::TYPE_PEOPLE,
    ]);
    $text = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $group->board_view_id,
        'type' => BoardColumn::TYPE_TEXT,
    ]);

    return [$owner, $workspace, $board, $group, $people, $text];
}

function addPermissionMember(Workspace $workspace, string $role = 'member'): User
{
    $user = User::factory()->create();
    DB::table('workspace_user')->insert([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

// ── Board permissions ──────────────────────────────────────────────────────

test('the board payload reports the permission mode and the viewer level', function () {
    [$owner, $workspace, $board] = createPermissionBoard('content');
    $member = addPermissionMember($workspace);

    $this->actingAs($member, 'api')->getJson("/api/boards/{$board->id}")
        ->assertOk()
        ->assertJsonPath('edit_permission', 'content')
        ->assertJsonPath('permission_level', 'content')
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('can_edit_structure', false)
        ->assertJsonPath('is_owner', false);

    $this->actingAs($owner, 'api')->getJson("/api/boards/{$board->id}")
        ->assertJsonPath('permission_level', 'full')
        ->assertJsonPath('is_owner', true);
});

test('only board owners can change the board permission mode', function () {
    [$owner, $workspace, $board] = createPermissionBoard();
    $member = addPermissionMember($workspace);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/permissions", ['edit_permission' => 'view_only'])
        ->assertForbidden();

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/permissions", ['edit_permission' => 'view_only'])
        ->assertOk()
        ->assertJsonPath('item.edit_permission', 'view_only');

    expect($board->fresh()->edit_permission)->toBe('view_only');
});

test('the board permission mode must be a known value', function () {
    [$owner, , $board] = createPermissionBoard();

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/permissions", ['edit_permission' => 'anything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('edit_permission');
});

test('edit content lets members edit items but not the structure', function () {
    [, $workspace, $board, $group, , $text] = createPermissionBoard('content');
    $member = addPermissionMember($workspace);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);

    $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'New task'])
        ->assertCreated();

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Hello']])
        ->assertOk();

    $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/columns", ['key' => 'budget', 'label' => 'Budget', 'type' => 'number'])
        ->assertForbidden();

    $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/groups", ['name' => 'New group'])
        ->assertForbidden();

    $this->actingAs($member, 'api')
        ->deleteJson("/api/boards/{$board->id}/columns/{$text->id}")
        ->assertForbidden();
});

test('edit content still lets members resize a column', function () {
    [, $workspace, $board, , , $text] = createPermissionBoard('content');
    $member = addPermissionMember($workspace);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$text->id}", ['width' => 260])
        ->assertOk();

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$text->id}", ['label' => 'Renamed'])
        ->assertForbidden();
});

test('assigned items only lets members edit just the items they are assigned to', function () {
    [, $workspace, $board, $group, $people, $text] = createPermissionBoard('assigned_items');
    $member = addPermissionMember($workspace);
    $assigned = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $assigned->values()->create(['column_id' => $people->id, 'value' => [$member->id]]);
    $other = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$assigned->id}/values", ['values' => [(string) $text->id => 'Mine']])
        ->assertOk();

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$other->id}/values", ['values' => [(string) $text->id => 'Not mine']])
        ->assertForbidden();

    $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['group_id' => $group->id, 'name' => 'New task'])
        ->assertForbidden();

    $this->actingAs($member, 'api')
        ->postJson("/api/boards/{$board->id}/items", ['parent_id' => $assigned->id, 'name' => 'A subitem of mine'])
        ->assertCreated();
});

test('assigned items only rejects a bulk action that includes someone else item', function () {
    [, $workspace, $board, $group, $people] = createPermissionBoard('assigned_items');
    $member = addPermissionMember($workspace);
    $assigned = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $assigned->values()->create(['column_id' => $people->id, 'value' => [$member->id]]);
    $other = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$assigned->id, $other->id]])
        ->assertForbidden();

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/archive", ['item_ids' => [$assigned->id]])
        ->assertOk();
});

test('view only blocks members but never the board owner', function () {
    [$owner, $workspace, $board, $group, , $text] = createPermissionBoard('view_only');
    $member = addPermissionMember($workspace);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Nope']])
        ->assertForbidden();

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Yes']])
        ->assertOk();
});

test('a workspace owner is never limited by the board mode', function () {
    [, $workspace, $board, $group] = createPermissionBoard('view_only');
    $workspace_owner = addPermissionMember($workspace, 'owner');

    $this->actingAs($workspace_owner, 'api')
        ->postJson("/api/boards/{$board->id}/groups", ['name' => 'Owner group'])
        ->assertCreated();
});

// ── Column permissions ─────────────────────────────────────────────────────

test('only board owners can restrict a column', function () {
    [$owner, $workspace, $board, , , $text] = createPermissionBoard();
    $member = addPermissionMember($workspace);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$text->id}/permissions", ['edit_restriction' => ['user_ids' => [$member->id]]])
        ->assertForbidden();

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$text->id}/permissions", ['edit_restriction' => ['user_ids' => [$member->id]]])
        ->assertOk()
        ->assertJsonPath('column.edit_restriction.user_ids', [$member->id]);
});

test('an edit restricted column rejects value changes from everyone else', function () {
    [$owner, $workspace, $board, $group, , $text] = createPermissionBoard();
    $allowed = addPermissionMember($workspace);
    $blocked = addPermissionMember($workspace);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $text->update(['edit_restriction' => ['user_ids' => [$allowed->id], 'team_ids' => []]]);

    $this->actingAs($blocked, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Blocked']])
        ->assertForbidden();

    $this->actingAs($allowed, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Allowed']])
        ->assertOk();

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Owner']])
        ->assertOk();

    $this->actingAs($blocked, 'api')->getJson("/api/boards/{$board->id}/columns")
        ->assertOk()
        ->assertJsonFragment(['id' => $text->id, 'can_edit_values' => false]);
});

test('a view restricted column and its values are hidden from everyone else', function () {
    [, $workspace, $board, $group, , $text] = createPermissionBoard();
    $allowed = addPermissionMember($workspace);
    $blocked = addPermissionMember($workspace);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $item->values()->create(['column_id' => $text->id, 'value' => 'Salary 100k']);
    $text->update(['view_restriction' => ['user_ids' => [$allowed->id], 'team_ids' => []]]);

    $blocked_columns = $this->actingAs($blocked, 'api')->getJson("/api/boards/{$board->id}/columns")->json('data');
    expect(collect($blocked_columns)->pluck('id'))->not->toContain($text->id);

    $this->actingAs($blocked, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonMissing(['Salary 100k']);

    $this->actingAs($allowed, 'api')->getJson("/api/boards/{$board->id}/items")
        ->assertOk()
        ->assertJsonPath("data.0.values.{$text->id}", 'Salary 100k');
});

test('a team listed on a restriction lets its members through', function () {
    [, $workspace, $board, $group, , $text] = createPermissionBoard();
    $member = addPermissionMember($workspace);
    $team_id = DB::table('account_teams')->insertGetId(['name' => 'Finance', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('account_team_user')->insert(['account_team_id' => $team_id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
    $item = BoardItem::factory()->create(['board_id' => $board->id, 'group_id' => $group->id]);
    $text->update(['edit_restriction' => ['user_ids' => [], 'team_ids' => [$team_id]]]);

    $this->actingAs($member, 'api')
        ->patchJson("/api/boards/{$board->id}/items/{$item->id}/values", ['values' => [(string) $text->id => 'Team edit']])
        ->assertOk();
});
