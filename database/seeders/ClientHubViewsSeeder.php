<?php

namespace Database\Seeders;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Seeder;

/**
 * Seeds the Client Hub board's tabs (`board_views`) so the real "rename tab" /
 * "add icon" / "switch tab to see different content" flow has something to
 * show out of the box. Client Hub's table itself stays frontend mock data
 * (`src/data/client-hub-data.ts`) by design, but its tabs are real —
 * `ClientHubBoard.tsx` resolves the board id via `GET /api/boards/client-hub`
 * and manages these rows through the same generic `boards/{item}/views`
 * endpoints every other board uses.
 *
 * Each non-primary tab demonstrates a different real filtering mechanism
 * (group-by, a quick-filter facet, the person filter) so switching tabs
 * genuinely narrows the table to different rows, not just a cosmetic label.
 */
class ClientHubViewsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $board = WorkspaceNavigationItem::where('view_key', 'client_hub')->first();

        if (! $board) {
            return;
        }

        // Idempotent: rebuild the tab list from scratch on every seed run.
        $board->views()->delete();

        $board->views()->create([
            'label' => 'Main table',
            'icon' => 'table',
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ]);

        $board->views()->create([
            'label' => 'By status',
            'icon' => 'chart',
            'position' => 1,
            'is_primary' => false,
            'row_height' => 'single',
            'group_by_option_id' => 'status',
        ]);

        $board->views()->create([
            'label' => 'Renewals',
            'icon' => 'clock',
            'position' => 2,
            'is_primary' => false,
            'row_height' => 'single',
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => [],
                // An empty PHP array json_encodes as `[]`, not `{}` — cast to an
                // object so the frontend's Record<string, string[]> reads it back
                // as an empty object rather than an empty list.
                'quick_filter_selections' => ['group' => ['renewal']],
                'advanced_filter_rows' => [],
            ],
        ]);

        $board->views()->create([
            'label' => 'Blake',
            'icon' => 'person',
            'position' => 3,
            'is_primary' => false,
            'row_height' => 'single',
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => ['blake'],
                'quick_filter_selections' => (object) [],
                'advanced_filter_rows' => [],
            ],
        ]);

        $board->views()->create([
            'label' => 'Sam',
            'icon' => 'person',
            'position' => 4,
            'is_primary' => false,
            'row_height' => 'single',
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => ['sam'],
                'quick_filter_selections' => (object) [],
                'advanced_filter_rows' => [],
            ],
        ]);
    }
}
