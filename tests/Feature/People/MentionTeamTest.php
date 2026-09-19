<?php

use App\Models\AccountTeam;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function createMentionTeamBoard(User $member): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($member->id, ['role' => 'member']);

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

test('a team mention only reaches the team members who belong to the board workspace', function () {
    $viewer = User::factory()->create();
    $board = createMentionTeamBoard($viewer);
    $inside = User::factory()->create();
    $board->workspace->users()->attach($inside->id, ['role' => 'member']);
    $outside = User::factory()->create();

    $design = AccountTeam::factory()->create(['name' => 'Design']);
    $design->members()->attach([$inside->id, $outside->id]);
    AccountTeam::factory()->create(['name' => 'Empty team']);

    $response = $this->actingAs($viewer, 'api')->getJson("/api/people/boards/{$board->id}/teams")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Design')
        ->and($response->json('data.0.member_ids'))->toBe([$inside->id]);
});

test('the team list is open to workspace members without the staff role but not to outsiders', function () {
    $member = User::factory()->create();
    $board = createMentionTeamBoard($member);

    $this->actingAs($member, 'api')->getJson("/api/people/boards/{$board->id}/teams")->assertOk();
    $this->actingAs(User::factory()->create(), 'api')->getJson("/api/people/boards/{$board->id}/teams")->assertForbidden();
});
