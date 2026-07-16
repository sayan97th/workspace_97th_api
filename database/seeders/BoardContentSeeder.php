<?php

namespace Database\Seeders;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds two demo boards that exercise the reusable "table board" engine
 * (`App\Http\Controllers\Board\*`) end to end: any number of tables (groups)
 * per board, one column of every supported type, items with values, two
 * views per board (a primary "Main table" plus a saved-filter view) to prove
 * the save/restore round trip, and a full comment thread — with a mention,
 * a reaction, a like and a file attachment — on the first item of each
 * board, so the item detail drawer's Updates tab has real data immediately.
 *
 * Deliberately fires model events (like WorkspaceSeeder): board items/views
 * rely on the `creating` hook from HasRandomBigId to assign their id.
 */
class BoardContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workspace = Workspace::where('slug', 'fulfillment')->first() ?? Workspace::query()->firstOrFail();
        $user_ids = User::query()->limit(5)->pluck('id')->all();

        // Idempotent: wipe any previously-seeded demo boards before recreating them.
        WorkspaceNavigationItem::where('workspace_id', $workspace->id)
            ->whereIn('label', ['Product Roadmap', 'Marketing Campaigns'])
            ->get()
            ->each(fn (WorkspaceNavigationItem $item) => $item->delete());

        $this->seedBoard(
            workspace: $workspace,
            label: 'Product Roadmap',
            group_names: ['Now', 'Next', 'Later'],
            items_per_group: 6,
            user_ids: $user_ids,
        );

        $this->seedBoard(
            workspace: $workspace,
            label: 'Marketing Campaigns',
            group_names: ['Active Campaigns', 'Ideas'],
            items_per_group: 5,
            user_ids: $user_ids,
        );
    }

    /**
     * @param  array<int, string>  $group_names
     * @param  array<int, int>  $user_ids
     */
    private function seedBoard(
        Workspace $workspace,
        string $label,
        array $group_names,
        int $items_per_group,
        array $user_ids,
    ): void {
        $next_position = (int) $workspace->navigationItems()->whereNull('parent_id')->max('position') + 1;

        /** @var WorkspaceNavigationItem $board */
        $board = $workspace->navigationItems()->create([
            'parent_id' => null,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => $label,
            'slug' => Str::slug($label),
            'display_style' => 'table',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_favorite' => false,
            'position' => $next_position,
        ]);

        $columns = $this->seedColumns($board);
        $groups = [];
        foreach ($group_names as $position => $name) {
            $groups[] = $board->groups()->create([
                'name' => $name,
                'accent_color' => ['#00c875', '#579bfc', '#a25ddc', '#fdab3d'][$position % 4],
                'position' => $position,
            ]);
        }

        $first_item = null;
        foreach ($groups as $group) {
            for ($i = 0; $i < $items_per_group; $i++) {
                $item = $this->seedItem($board, $group, $columns, $user_ids, $i);
                $first_item ??= $item;
            }
        }

        $this->seedViews($board, $columns);

        if ($first_item !== null) {
            $this->seedComments($first_item, $user_ids);
        }
    }

    /**
     * @return array<string, BoardColumn>
     */
    private function seedColumns(WorkspaceNavigationItem $board): array
    {
        $definitions = [
            ['key' => 'notes', 'label' => 'Notes', 'type' => BoardColumn::TYPE_TEXT, 'width' => 220],
            ['key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_STATUS, 'width' => 140, 'config' => [
                'options' => [
                    ['id' => 'not_started', 'label' => 'Not Started', 'color' => '#c4c4c4'],
                    ['id' => 'in_progress', 'label' => 'Working on it', 'color' => '#fdab3d'],
                    ['id' => 'done', 'label' => 'Done', 'color' => '#00c875'],
                ],
            ]],
            ['key' => 'owner', 'label' => 'Owner', 'type' => BoardColumn::TYPE_PEOPLE, 'width' => 130],
            ['key' => 'due_date', 'label' => 'Due Date', 'type' => BoardColumn::TYPE_DATE, 'width' => 130],
            ['key' => 'tags', 'label' => 'Tags', 'type' => BoardColumn::TYPE_TAGS, 'width' => 180, 'config' => [
                'options' => [
                    ['id' => 'design', 'label' => 'Design', 'color' => '#a25ddc'],
                    ['id' => 'backend', 'label' => 'Backend', 'color' => '#579bfc'],
                    ['id' => 'urgent', 'label' => 'Urgent', 'color' => '#e2445c'],
                ],
            ]],
            ['key' => 'budget', 'label' => 'Budget', 'type' => BoardColumn::TYPE_NUMBER, 'width' => 110],
            ['key' => 'is_blocked', 'label' => 'Blocked', 'type' => BoardColumn::TYPE_CHECKBOX, 'width' => 90],
        ];

        $columns = [];
        foreach ($definitions as $position => $definition) {
            $columns[$definition['key']] = $board->columns()->create([
                ...$definition,
                'position' => $position,
            ]);
        }

        return $columns;
    }

    /**
     * @param  array<string, BoardColumn>  $columns
     * @param  array<int, int>  $user_ids
     */
    private function seedItem(WorkspaceNavigationItem $board, BoardGroup $group, array $columns, array $user_ids, int $index): BoardItem
    {
        $statuses = ['not_started', 'in_progress', 'done'];
        $tag_sets = [['design'], ['backend', 'urgent'], ['design', 'backend'], []];

        $item = $board->items()->create([
            'group_id' => $group->id,
            'name' => "{$group->name} item ".($index + 1),
            'position' => $index,
        ]);

        $item->values()->createMany([
            ['column_id' => $columns['notes']->id, 'value' => 'Seeded demo notes for this item.'],
            ['column_id' => $columns['status']->id, 'value' => $statuses[$index % 3]],
            ['column_id' => $columns['owner']->id, 'value' => $user_ids === [] ? [] : [$user_ids[$index % count($user_ids)]]],
            ['column_id' => $columns['due_date']->id, 'value' => now()->addDays($index)->toDateString()],
            ['column_id' => $columns['tags']->id, 'value' => $tag_sets[$index % 4]],
            ['column_id' => $columns['budget']->id, 'value' => ($index + 1) * 500],
            ['column_id' => $columns['is_blocked']->id, 'value' => $index % 3 === 0],
        ]);

        return $item;
    }

    /**
     * Seeds one item with a full comment thread — an update with a
     * mention, an emoji reaction, a like and a file attachment, plus a
     * reply — so the drawer's Updates tab has real data to show right away.
     *
     * @param  array<int, int>  $user_ids
     */
    private function seedComments(BoardItem $item, array $user_ids): void
    {
        if (count($user_ids) < 2) {
            return;
        }

        [$author_id, $replier_id] = $user_ids;
        $mentioned_id = $user_ids[2] ?? $replier_id;

        $comment = $item->comments()->create([
            'user_id' => $author_id,
            'body' => 'Kicking this off — @'.User::find($mentioned_id)?->full_name.' can you take a first pass?',
        ]);
        $comment->mentions()->create(['user_id' => $mentioned_id]);
        $comment->likes()->create(['user_id' => $replier_id]);
        $comment->reactions()->create(['user_id' => $replier_id, 'emoji' => '👍']);
        $comment->views()->create(['user_id' => $replier_id]);

        $attachment_path = "board-comment-attachments/{$item->id}/".Str::uuid().'.txt';
        Storage::disk('public')->put($attachment_path, "Seeded demo attachment for {$item->name}.");
        $comment->attachments()->create([
            'uploaded_by_id' => $author_id,
            'file_name' => 'kickoff-notes.txt',
            'file_path' => $attachment_path,
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size_bytes' => Storage::disk('public')->size($attachment_path),
        ]);

        $comment->replies()->create([
            'item_id' => $item->id,
            'user_id' => $replier_id,
            'body' => 'On it — will have notes ready by end of day.',
        ]);
    }

    /**
     * @param  array<string, BoardColumn>  $columns
     */
    private function seedViews(WorkspaceNavigationItem $board, array $columns): void
    {
        $board->views()->create([
            'label' => 'Main table',
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ]);

        $board->views()->create([
            'label' => 'Blocked items',
            'position' => 1,
            'is_primary' => false,
            'row_height' => 'single',
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => [],
                // An empty PHP array json_encodes as `[]`, not `{}` — cast to an
                // object so the frontend's Record<string, string[]> reads it back
                // as an empty object rather than an empty list.
                'quick_filter_selections' => (object) [],
                'advanced_filter_rows' => [[
                    'id' => 'seed-blocked',
                    'column_id' => (string) $columns['is_blocked']->id,
                    'condition' => 'equals',
                    'value' => 'true',
                ]],
            ],
        ]);
    }
}
