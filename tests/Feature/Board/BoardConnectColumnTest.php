<?php

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;

function connectTestBoard(Workspace $workspace, string $label, array $attributes = []): WorkspaceNavigationItem
{
    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
        'label' => $label,
        ...$attributes,
    ]);
}

function connectTestItem(WorkspaceNavigationItem $board, BoardGroup $group, string $name, int $position = 0): BoardItem
{
    return $board->items()->create(['group_id' => $group->id, 'name' => $name, 'position' => $position]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->roadmap = connectTestBoard($this->workspace, 'Roadmap');
    $this->sprints = connectTestBoard($this->workspace, 'Sprints');

    $this->roadmap_group = BoardGroup::factory()->create(['board_id' => $this->roadmap->id]);
    $this->sprint_group = BoardGroup::factory()->create(['board_id' => $this->sprints->id, 'name' => 'Sprint 51', 'accent_color' => '#e2445c']);

    $this->column = BoardColumn::factory()->create([
        'board_id' => $this->roadmap->id,
        'type' => BoardColumn::TYPE_CONNECT_BOARD,
        'config' => ['linked_board_id' => $this->sprints->id],
    ]);
    $this->status = BoardColumn::factory()->create([
        'board_id' => $this->sprints->id,
        'type' => BoardColumn::TYPE_STATUS,
        'label' => 'Status',
        'config' => ['options' => [['id' => 'done', 'label' => 'Done', 'color' => '#00c875']]],
    ]);

    $this->feature = connectTestItem($this->roadmap, $this->roadmap_group, 'Reports feature');
    $this->task = connectTestItem($this->sprints, $this->sprint_group, 'Users notified when reports are finished');
    $this->task->values()->create(['column_id' => $this->status->id, 'value' => 'done']);
});

function connectItemsUrl(WorkspaceNavigationItem $board, BoardColumn $column): string
{
    return "/api/boards/{$board->id}/columns/{$column->id}/connect-items";
}

function connectSettingsUrl(WorkspaceNavigationItem $board, BoardColumn $column): string
{
    return "/api/boards/{$board->id}/columns/{$column->id}/connect-settings";
}

test('connect items lists the connected board with its groups, columns and searchable texts', function () {
    $this->actingAs($this->user, 'api')
        ->getJson(connectItemsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonPath('hidden_board_count', 0)
        ->assertJsonPath('boards.0.id', $this->sprints->id)
        ->assertJsonPath('boards.0.name', 'Sprints')
        ->assertJsonPath('boards.0.can_add_items', true)
        ->assertJsonPath('boards.0.groups.0.name', 'Sprint 51')
        ->assertJsonPath('boards.0.groups.0.color', '#e2445c')
        ->assertJsonPath('boards.0.columns.0.label', 'Status')
        ->assertJsonPath('boards.0.items.0.name', 'Users notified when reports are finished')
        ->assertJsonPath('boards.0.items.0.color', '#e2445c')
        ->assertJsonPath("boards.0.items.0.texts.{$this->status->id}", 'Done');
});

test('connect items resolve tags shared from another board to their labels', function () {
    $tags = BoardColumn::factory()->create(['board_id' => $this->sprints->id, 'type' => BoardColumn::TYPE_TAGS, 'label' => 'Tags']);
    $own_tag = $this->sprints->tags()->create(['label' => '#qa', 'color' => '#579bfc', 'position' => 0]);
    $public_tag = $this->roadmap->tags()->create(['label' => '#seo', 'color' => '#00c875', 'position' => 0]);
    $this->task->values()->create(['column_id' => $tags->id, 'value' => [(string) $own_tag->id, (string) $public_tag->id]]);

    $this->actingAs($this->user, 'api')
        ->getJson(connectItemsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonPath("boards.0.items.0.texts.{$tags->id}", '#qa, #seo');
});

test('connect items leaves out private boards the user may not open', function () {
    $this->sprints->update(['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $this->actingAs($this->user, 'api')
        ->getJson(connectItemsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonCount(0, 'boards')
        ->assertJsonPath('hidden_board_count', 1);
});

test('connect items is only served for a connect boards column of the board', function () {
    $text_column = BoardColumn::factory()->create(['board_id' => $this->roadmap->id]);

    $this->actingAs($this->user, 'api')->getJson(connectItemsUrl($this->roadmap, $text_column))->assertNotFound();
    $this->actingAs($this->user, 'api')->getJson(connectItemsUrl($this->sprints, $this->column))->assertNotFound();
});

test('settings save several boards and keep the first one as linked_board_id', function () {
    $clients = connectTestBoard($this->workspace, 'Clients');

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), [
            'linked_board_ids' => [$clients->id, $this->sprints->id],
            'allow_multiple_items' => false,
        ])
        ->assertOk()
        ->assertJsonPath('column.config.linked_board_ids', [$clients->id, $this->sprints->id])
        ->assertJsonPath('column.config.linked_board_id', $clients->id)
        ->assertJsonPath('column.config.allow_multiple_items', false)
        ->assertJsonPath('column.config.two_way', false);
});

test('settings reject boards that do not exist or are hidden', function () {
    $hidden = connectTestBoard($this->workspace, 'Secret', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$hidden->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('linked_board_ids.0');

});

test('a two-way connection adds a column on the connected board and backfills it', function () {
    $this->feature->values()->create(['column_id' => $this->column->id, 'value' => [(string) $this->task->id]]);

    $response = $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$this->sprints->id], 'two_way' => true])
        ->assertOk()
        ->assertJsonPath('column.config.two_way', true);

    $reverse = BoardColumn::findOrFail($response->json('column.config.reverse_column_id'));
    expect($reverse->board_id)->toBe($this->sprints->id)
        ->and($reverse->type)->toBe(BoardColumn::TYPE_CONNECT_BOARD)
        ->and($reverse->label)->toBe('Roadmap')
        ->and($reverse->config['reverse_column_id'])->toBe($this->column->id)
        ->and($this->task->values()->where('column_id', $reverse->id)->value('value'))->toBe([(string) $this->feature->id]);
});

test('a two-way connection mirrors links added and removed from either side', function () {
    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$this->sprints->id], 'two_way' => true])
        ->assertOk();
    $reverse_id = $this->column->fresh()->config['reverse_column_id'];
    $values_url = "/api/boards/{$this->roadmap->id}/items/{$this->feature->id}/values";

    $this->actingAs($this->user, 'api')
        ->patchJson($values_url, ['values' => [(string) $this->column->id => [(string) $this->task->id]]])
        ->assertOk();
    expect($this->task->values()->where('column_id', $reverse_id)->value('value'))->toBe([(string) $this->feature->id]);

    $this->actingAs($this->user, 'api')
        ->patchJson($values_url, ['values' => [(string) $this->column->id => null]])
        ->assertOk();
    expect($this->task->values()->where('column_id', $reverse_id)->value('value'))->toBeNull();

    // From the connected board's side back to this one.
    $this->actingAs($this->user, 'api')
        ->patchJson("/api/boards/{$this->sprints->id}/items/{$this->task->id}/values", ['values' => [(string) $reverse_id => [(string) $this->feature->id]]])
        ->assertOk();
    expect($this->feature->values()->where('column_id', $this->column->id)->value('value'))->toBe([(string) $this->task->id]);
});

test('turning two-way off keeps the other column but stops syncing', function () {
    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$this->sprints->id], 'two_way' => true])
        ->assertOk();
    $reverse_id = $this->column->fresh()->config['reverse_column_id'];

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$this->sprints->id], 'two_way' => false])
        ->assertOk()
        ->assertJsonMissingPath('column.config.reverse_column_id');

    $reverse = BoardColumn::findOrFail($reverse_id);
    expect($reverse->config['two_way'])->toBeFalse()
        ->and($reverse->config)->not->toHaveKey('reverse_column_id');

    $this->actingAs($this->user, 'api')
        ->patchJson("/api/boards/{$this->roadmap->id}/items/{$this->feature->id}/values", ['values' => [(string) $this->column->id => [(string) $this->task->id]]])
        ->assertOk();
    expect($this->task->values()->where('column_id', $reverse_id)->exists())->toBeFalse();
});

test('a generic column update keeps the connect settings', function () {
    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$this->sprints->id], 'allow_multiple_items' => false])
        ->assertOk();

    $this->actingAs($this->user, 'api')
        ->patchJson("/api/boards/{$this->roadmap->id}/columns/{$this->column->id}", ['config' => [...$this->column->fresh()->config, 'validation' => ['required' => true]]])
        ->assertOk()
        ->assertJsonPath('column.config.linked_board_ids', [$this->sprints->id])
        ->assertJsonPath('column.config.allow_multiple_items', false);
});

test('settings keep an already connected private board and drop a deleted one', function () {
    $secret = connectTestBoard($this->workspace, 'Secret', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);
    $gone = connectTestBoard($this->workspace, 'Gone');
    $this->column->update(['config' => ['linked_board_ids' => [$secret->id, $gone->id], 'linked_board_id' => $secret->id]]);
    $gone->delete();

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$secret->id, $gone->id, $this->sprints->id]])
        ->assertOk()
        ->assertJsonPath('column.config.linked_board_ids', [$secret->id, $this->sprints->id]);
});

test('disconnecting every board leaves the column unconnected', function () {
    $gone = connectTestBoard($this->workspace, 'Gone');
    $this->column->update(['config' => ['linked_board_ids' => [$this->sprints->id, $gone->id], 'linked_board_id' => $this->sprints->id]]);
    $gone->delete();

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => [$gone->id]])
        ->assertOk()
        ->assertJsonPath('column.config.linked_board_ids', [])
        ->assertJsonMissingPath('column.config.linked_board_id');

    $this->actingAs($this->user, 'api')
        ->putJson(connectSettingsUrl($this->roadmap, $this->column), ['linked_board_ids' => []])
        ->assertOk()
        ->assertJsonPath('column.config.linked_board_ids', []);
});

test('the column contents are hidden from users who cannot open every connected board', function () {
    $secret = connectTestBoard($this->workspace, 'Secret', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);
    $this->column->update(['config' => ['linked_board_ids' => [$this->sprints->id, $secret->id], 'linked_board_id' => $this->sprints->id]]);

    $this->actingAs($this->user, 'api')
        ->getJson(connectItemsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonCount(0, 'boards')
        ->assertJsonPath('hidden_board_count', 1);
});

test('connect settings list the connected boards with their privacy and path', function () {
    $secret = connectTestBoard($this->workspace, 'Secret', ['board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE]);
    $folder = connectTestBoard($this->workspace, 'Dev folder', ['type' => WorkspaceNavigationItem::TYPE_GROUP]);
    $this->sprints->update(['parent_id' => $folder->id, 'board_type' => WorkspaceNavigationItem::BOARD_TYPE_PRIVATE, 'created_by_id' => $this->user->id]);
    $this->column->update(['config' => ['linked_board_ids' => [$this->sprints->id, $secret->id], 'linked_board_id' => $this->sprints->id, 'allow_multiple_items' => false]]);

    $this->actingAs($this->user, 'api')
        ->getJson(connectSettingsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonPath('allow_multiple_items', false)
        ->assertJsonPath('two_way', false)
        ->assertJsonPath('boards.0.name', 'Sprints')
        ->assertJsonPath('boards.0.is_private', true)
        ->assertJsonPath('boards.0.is_accessible', true)
        ->assertJsonPath('boards.0.path', [$this->workspace->name, 'Dev folder'])
        ->assertJsonPath('boards.1.name', null)
        ->assertJsonPath('boards.1.is_accessible', false);
});

test('connect items carry the raw values and definitions of mirrored columns', function () {
    BoardColumn::factory()->create([
        'board_id' => $this->roadmap->id,
        'type' => BoardColumn::TYPE_MIRROR,
        'config' => ['source_column_id' => $this->column->id, 'mirrored_column_id' => $this->status->id],
    ]);

    $this->actingAs($this->user, 'api')
        ->getJson(connectItemsUrl($this->roadmap, $this->column))
        ->assertOk()
        ->assertJsonPath('boards.0.mirrored_columns.0.id', $this->status->id)
        ->assertJsonPath('boards.0.mirrored_columns.0.type', BoardColumn::TYPE_STATUS)
        ->assertJsonPath('boards.0.mirrored_columns.0.options.0.label', 'Done')
        ->assertJsonPath("boards.0.items.0.values.{$this->status->id}", 'done');
});

test('connect and mirror columns describe their linkage, any column keeps a description', function () {
    $mirror = BoardColumn::factory()->create([
        'board_id' => $this->roadmap->id,
        'type' => BoardColumn::TYPE_MIRROR,
        'label' => 'Tasks Status',
        'config' => ['source_column_id' => $this->column->id, 'mirrored_column_id' => $this->status->id],
    ]);

    $this->actingAs($this->user, 'api')
        ->patchJson("/api/boards/{$this->roadmap->id}/columns/{$mirror->id}", ['config' => [...$mirror->config, 'description' => 'Task status of the linked tasks']])
        ->assertOk()
        ->assertJsonPath('column.config.description', 'Task status of the linked tasks')
        ->assertJsonPath('column.linkage.boards.0.name', 'Sprints')
        ->assertJsonPath('column.linkage.mirrored_column.label', 'Status')
        ->assertJsonPath('column.linkage.mirrored_column.board_name', 'Sprints');

    $columns = $this->actingAs($this->user, 'api')->getJson("/api/boards/{$this->roadmap->id}/columns")->assertOk()->json();
    $connect = collect($columns['data'] ?? $columns)->firstWhere('id', $this->column->id);
    expect($connect['linkage']['boards'][0]['name'])->toBe('Sprints')
        ->and($connect['linkage']['mirrored_column'])->toBeNull();
});
