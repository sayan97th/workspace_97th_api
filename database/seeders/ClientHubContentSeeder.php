<?php

namespace Database\Seeders;

use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds Client Hub's table content — columns, groups, items and values —
 * from the account-team roster, contract statuses and dates that used to be
 * hard-coded in the frontend (originally `src/data/client-hub-data.ts`, back
 * when Client Hub had its own bespoke `ClientHubBoard.tsx` component). Client
 * Hub is now a fully generic board like any other: no special frontend
 * component, no `view_key` — it's just a `WorkspaceNavigationItem` leaf
 * (seeded by {@see WorkspaceSeeder}) that this seeder populates with real
 * columns/groups/items, rendered automatically by the generic `TableBoardView`
 * engine.
 *
 * Runs after {@see ClientHubViewsSeeder} so its tabs already exist
 * (columns/groups auto-attach to the primary "Main table" tab via
 * `BelongsToBoardView`), and patches the "Renewals"/"Blake"/"Sam" tabs' saved
 * filters — seeded there with placeholder ids before any real group/user
 * existed — to point at the real rows this seeder creates.
 *
 * Idempotent: rebuilds the roster's workspace membership, and the board's
 * columns/groups/items, from scratch on every run.
 */
class ClientHubContentSeeder extends Seeder
{
    /**
     * Client Hub's account team, in the exact order the old frontend mock
     * cycled through when fanning out "Team" assignments across rows.
     *
     * @var array<int, array{first: string, last: string}>
     */
    private array $roster = [
        ['first' => 'Josh', 'last' => 'Moody'],
        ['first' => 'Blake', 'last' => 'Denton'],
        ['first' => 'Brandon', 'last' => 'Stewart'],
        ['first' => 'Rachel', 'last' => 'Tonkovich'],
        ['first' => 'Paxton', 'last' => 'Gray'],
        ['first' => 'Hayley', 'last' => 'Robinson'],
        ['first' => 'Sam', 'last' => 'Rivera'],
        ['first' => 'Haley', 'last' => 'Brooks'],
        ['first' => 'Jon', 'last' => 'Mattingly'],
        ['first' => 'Danny', 'last' => 'Olsen'],
        ['first' => 'Mike', 'last' => 'Powell'],
        ['first' => 'Jasmin', 'last' => 'Cole'],
        ['first' => 'Kate', 'last' => 'Sherwood'],
        ['first' => 'Devin', 'last' => 'Marsh'],
        ['first' => 'Nora', 'last' => 'Fields'],
        ['first' => 'Owen', 'last' => 'Hart'],
        ['first' => 'Priya', 'last' => 'Nair'],
        ['first' => 'Liam', 'last' => 'Foster'],
        ['first' => 'Maya', 'last' => 'Ortiz'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $board = WorkspaceNavigationItem::where('label', 'Client Hub')->first();

        if (! $board) {
            return;
        }

        $workspace = Workspace::findOrFail($board->workspace_id);
        $roster_ids = $this->seedRoster($workspace);

        // Idempotent: wipe previously-seeded columns/groups (and, via cascade,
        // the items/values hanging off them) before recreating them.
        $board->groups()->delete();
        $board->columns()->delete();

        $columns = $this->seedColumns($board);
        $groups = $this->seedGroups($board);

        $franklincovey_item = null;
        foreach ($this->activeContracts() as $index => $data) {
            $item = $this->seedItem($board, $groups['active'], $columns, $roster_ids, $index, $data);
            if ($data['name'] === 'FranklinCovey AUS/NZ') {
                $franklincovey_item = $item;
            }
        }

        $renewal_group_ids = [];
        foreach ($this->renewalContracts() as $index => $data) {
            $item = $this->seedItem($board, $groups['renewal'], $columns, $roster_ids, $index, $data);
            $renewal_group_ids[] = $item->id;
        }

        if ($franklincovey_item) {
            $this->seedFranklinCoveyThread($franklincovey_item, $roster_ids);
        }

        $this->patchSeededViews($board, $groups['renewal']->id, $roster_ids);
    }

    /**
     * Creates (or reuses) the account-team roster as real users and enrolls
     * them as owners of Client Hub's workspace, so the real "Team" people
     * column has a real, resolvable set of assignable people.
     *
     * @return array<int, int> user ids, in roster order
     */
    private function seedRoster(Workspace $workspace): array
    {
        $user_ids = [];

        foreach ($this->roster as $person) {
            $email = strtolower($person['first'].'.'.$person['last']).'@97thfloor.com';

            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::factory()->create([
                    'first_name' => $person['first'],
                    'last_name' => $person['last'],
                    'email' => $email,
                ]);
            }

            $workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'owner', 'is_recent' => true]]);

            $user_ids[] = $user->id;
        }

        return $user_ids;
    }

    /**
     * @return array<string, BoardColumn>
     */
    private function seedColumns(WorkspaceNavigationItem $board): array
    {
        $definitions = [
            // "Adjust Client Relationship Status with EMOJI" per Client Hub's
            // board description: ✅ good, ⚠️ caution, 🚩 alert, 🚀 opportunity.
            ['key' => 'client_relationship', 'label' => 'Client...', 'type' => BoardColumn::TYPE_STATUS, 'width' => 70, 'config' => [
                'options' => [
                    ['id' => 'good', 'label' => '✅ Good', 'color' => '#00c875'],
                    ['id' => 'caution', 'label' => '⚠️ Caution', 'color' => '#f5b731'],
                    ['id' => 'alert', 'label' => '🚩 Alert', 'color' => '#e2445c'],
                    ['id' => 'opportunity', 'label' => '🚀 Opportunity', 'color' => '#579bfc'],
                ],
            ]],
            ['key' => 'team', 'label' => 'Team', 'type' => BoardColumn::TYPE_PEOPLE, 'width' => 118],
            ['key' => 'products', 'label' => 'Product(s)', 'type' => BoardColumn::TYPE_TAGS, 'width' => 152, 'config' => [
                'options' => [
                    ['id' => 'ads', 'label' => 'Ads', 'color' => '#fdab3d'],
                    ['id' => 'content', 'label' => 'Content', 'color' => '#a25ddc'],
                    ['id' => 'seo', 'label' => 'SEO', 'color' => '#00c875'],
                ],
            ]],
            ['key' => 'kpi', 'label' => 'KPI', 'type' => BoardColumn::TYPE_TEXT, 'width' => 150],
            ['key' => 'status', 'label' => 'Status', 'type' => BoardColumn::TYPE_STATUS, 'width' => 128, 'config' => [
                'options' => [
                    ['id' => 'active', 'label' => 'Active', 'color' => '#00c875'],
                    ['id' => 'renewal', 'label' => 'Renewal', 'color' => '#f5b731'],
                    ['id' => 'expired', 'label' => 'Expired', 'color' => '#e2445c'],
                ],
            ]],
            ['key' => 'partner', 'label' => 'Partner Program', 'type' => BoardColumn::TYPE_CHECKBOX, 'width' => 150],
            ['key' => 'start', 'label' => 'Start of Current Contract', 'type' => BoardColumn::TYPE_DATE, 'width' => 180],
            ['key' => 'end', 'label' => 'End of Contract', 'type' => BoardColumn::TYPE_DATE, 'width' => 150],
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
     * @return array<string, BoardGroup>
     */
    private function seedGroups(WorkspaceNavigationItem $board): array
    {
        return [
            'active' => $board->groups()->create(['name' => 'Active Contracts', 'accent_color' => '#00c875', 'position' => 0]),
            'optout' => $board->groups()->create(['name' => 'Opt Out Period', 'accent_color' => '#00c875', 'position' => 1]),
            'renewal' => $board->groups()->create(['name' => 'Renewal Period', 'accent_color' => '#00c875', 'position' => 2]),
        ];
    }

    /**
     * @param  array<string, BoardColumn>  $columns
     * @param  array<int, int>  $roster_ids
     * @param  array<string, mixed>  $data
     */
    private function seedItem(
        WorkspaceNavigationItem $board,
        BoardGroup $group,
        array $columns,
        array $roster_ids,
        int $index,
        array $data,
    ): BoardItem {
        $item = $board->items()->create([
            'group_id' => $group->id,
            'name' => $data['name'],
            'position' => $index,
        ]);

        $roster_count = count($roster_ids);
        $assigned_ids = [];
        for ($member = 0; $member < $data['team_count']; $member++) {
            $assigned_ids[] = $roster_ids[($index + $member) % $roster_count];
        }

        $item->values()->createMany([
            ['column_id' => $columns['client_relationship']->id, 'value' => $data['client_relationship'] ?? null],
            ['column_id' => $columns['team']->id, 'value' => $assigned_ids],
            ['column_id' => $columns['products']->id, 'value' => $data['products']],
            ['column_id' => $columns['kpi']->id, 'value' => $data['kpi'] ?? null],
            ['column_id' => $columns['status']->id, 'value' => $data['status']],
            ['column_id' => $columns['partner']->id, 'value' => $data['partner'] ?? false],
            ['column_id' => $columns['start']->id, 'value' => $data['start'] ?? null],
            ['column_id' => $columns['end']->id, 'value' => $data['end'] ?? null],
        ]);

        return $item;
    }

    /**
     * Seeds the FranklinCovey AUS/NZ update thread — a mention, two replies,
     * and two follow-up updates — matching the hand-authored thread the old
     * frontend mock seeded for this same row, so the drawer's Updates tab has
     * real data immediately.
     *
     * @param  array<int, int>  $roster_ids
     */
    private function seedFranklinCoveyThread(BoardItem $item, array $roster_ids): void
    {
        $mike_id = $roster_ids[10];
        $brandon_id = $roster_ids[2];

        $kickoff = $item->comments()->create([
            'user_id' => $mike_id,
            'body' => '@Brandon Stewart I had a productive meeting with Kayleigh about au/nz "leadership" ranking. '
                .'The leadership blog post on the separate language/region pages have no hreflang tags and are exactly the same. '
                .'We talked about escalating the hreflang issue with their dev team and potentially diversifying the article on leadership.',
        ]);
        $kickoff->mentions()->create(['user_id' => $brandon_id]);

        $kickoff->replies()->create([
            'item_id' => $item->id,
            'user_id' => $brandon_id,
            'body' => '@Mike Powell thanks for looking into this. Would we need to diversify if the hreflang tags are set up properly? '
                .'Or is the diversifying a precaution in case the hreflang tags are not all implemented correctly?',
        ]);
        $kickoff->replies()->create([
            'item_id' => $item->id,
            'user_id' => $mike_id,
            'body' => 'No need to diversify if the hreflang tags are set up correctly! Diversifying the content was a plan B '
                .'as Kayleigh mentioned you guys have had trouble getting the dev team to prioritize those language tags.',
        ]);

        $item->comments()->create([
            'user_id' => $brandon_id,
            'body' => "They have reached out to us and let us know that they will be opting out at the end of June. "
                ."The team has greatly appreciated working with us, but their management is being very cautious with their spend.\n\n"
                ."We've gotten great results for them over the past year.",
        ]);

        $item->comments()->create([
            'user_id' => $brandon_id,
            'body' => "Things are going well. We're still trying to get them to sign the new SOW because their procurement "
                .'department keeps pushing back that there\'s an existing SOW, but it\'s for the corporate site. '
                .'All is going well, and we\'ve sent them an update on metrics over the last 12 months showing the continual growth they\'ve had.',
        ]);
    }

    /**
     * {@see ClientHubViewsSeeder} seeds the "Renewals"/"Blake"/"Sam" tabs
     * before any real group/user exists, so their saved filters reference
     * placeholder ids ('renewal', 'blake', 'sam'). Now that real rows exist,
     * point those filters at them so switching tabs actually narrows the
     * table.
     *
     * @param  array<int, int>  $roster_ids
     */
    private function patchSeededViews(WorkspaceNavigationItem $board, int $renewal_group_id, array $roster_ids): void
    {
        $blake_id = $roster_ids[1];
        $sam_id = $roster_ids[6];

        $board->views()->where('label', 'Renewals')->update([
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => [],
                'quick_filter_selections' => ['group' => [(string) $renewal_group_id]],
                'advanced_filter_rows' => [],
            ],
        ]);

        $board->views()->where('label', 'Blake')->update([
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => [(string) $blake_id],
                'quick_filter_selections' => (object) [],
                'advanced_filter_rows' => [],
            ],
        ]);

        $board->views()->where('label', 'Sam')->update([
            'filter_state' => [
                'search_query' => '',
                'search_column_ids' => [],
                'selected_person_ids' => [(string) $sam_id],
                'quick_filter_selections' => (object) [],
                'advanced_filter_rows' => [],
            ],
        ]);
    }

    /**
     * An end date given without a year rolls over to the year after `$start`
     * when it falls chronologically before `$start`'s month/day (an annual
     * contract's renewal date), otherwise it lands in the same year.
     */
    private function resolveEndDate(string $start, string $end_without_year, ?int $anchor_year = null): string
    {
        $start_date = Carbon::parse($start);
        $year = $anchor_year ?? $start_date->year;
        $candidate = Carbon::parse($end_without_year.', '.$year);

        if ($candidate->lt($start_date)) {
            $candidate = $candidate->addYear();
        }

        return $candidate->toDateString();
    }

    /**
     * The "Active Contracts" table, ported verbatim from the old frontend
     * mock (`CLIENT_HUB_GROUPS` in `src/data/client-hub-data.ts`). Purely
     * decorative mock-only affordances with no backing data model — the
     * item's star flag, its sub-item count, and the "+N" overflow badges on
     * team/product counts — are intentionally dropped rather than faked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeContracts(): array
    {
        return [
            ['name' => 'Hale Centre Theatre - Arizona', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['ads'], 'kpi' => 'PPC', 'status' => 'active', 'start' => '2021-04-20'],
            ['name' => 'Hale Centre Theatre - Sandy', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['ads'], 'kpi' => 'PPC', 'status' => 'active', 'start' => '2021-03-01'],
            ['name' => 'Global Citizen Year/Tilting Futures', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['content', 'seo'], 'kpi' => 'Deliverables', 'status' => 'active', 'start' => '2023-09-01', 'end' => '2024-06-30'],
            ['name' => 'FranklinCovey AUS/NZ', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'status' => 'active'],
            ['name' => 'Sold.com', 'client_relationship' => 'caution', 'team_count' => 2, 'products' => ['content'], 'kpi' => 'PPC', 'status' => 'active', 'start' => '2021-03-01', 'end' => '2025-02-28'],
            ['name' => 'Salesforce', 'team_count' => 1, 'products' => ['seo'], 'kpi' => 'Links, SEO Consul...', 'status' => 'active', 'start' => '2020-01-01'],
            ['name' => 'Princess Cruises', 'team_count' => 2, 'products' => ['seo'], 'kpi' => 'Organic Traffic', 'status' => 'active', 'start' => '2024-03-01', 'end' => '2024-11-30'],
            ['name' => 'Schneider Electric', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo'], 'kpi' => 'SEO, Content, PP...', 'status' => 'active', 'start' => '2020-03-17'],
            ['name' => 'Plaza Tire', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['ads'], 'kpi' => 'Calls, Quotes, Pur...', 'status' => 'active', 'start' => '2024-07-01'],
            ['name' => 'Blue Haven', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo'], 'kpi' => 'Links, SEO Consul...', 'status' => 'active', 'start' => '2020-01-01', 'end' => '2024-07-31'],
            ['name' => 'Weave', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Deliverables', 'status' => 'active', 'start' => '2023-09-19', 'end' => '2024-03-18'],
            ['name' => 'College Ave', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Deliverables, key...', 'status' => 'active', 'start' => '2023-02-01', 'end' => '2024-03-31'],
            ['name' => 'FranklinCovey', 'client_relationship' => 'caution', 'team_count' => 2, 'products' => ['content'], 'kpi' => 'Deliverables', 'status' => 'active', 'start' => '2021-07-01'],
            ['name' => 'Check Point', 'client_relationship' => 'caution', 'team_count' => 1, 'products' => ['content'], 'kpi' => 'Deliverables', 'status' => 'active', 'start' => '2021-09-01'],
            ['name' => 'Proponent', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo'], 'kpi' => 'SEO', 'status' => 'active', 'start' => '2025-09-01', 'end' => $this->resolveEndDate('2025-09-01', 'Aug 31')],
            ['name' => 'Holland America', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Organic Traffic', 'status' => 'active', 'start' => '2025-09-01', 'end' => $this->resolveEndDate('2025-09-01', 'Aug 31')],
            ['name' => 'Ontraport', 'team_count' => 1, 'products' => ['ads'], 'status' => 'active', 'start' => '2025-09-01', 'end' => $this->resolveEndDate('2025-09-01', 'Aug 31')],
            ['name' => 'Andy Frisella', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['content'], 'kpi' => 'Deliverables', 'status' => 'active', 'start' => '2021-08-01', 'end' => '2025-07-31'],
        ];
    }

    /**
     * The "Renewal Period" table, ported verbatim from the old frontend mock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function renewalContracts(): array
    {
        return [
            ['name' => 'EasyPost', 'client_relationship' => 'good', 'team_count' => 2, 'products' => ['seo'], 'kpi' => 'Organic Traffic', 'status' => 'renewal', 'start' => '2025-07-01', 'end' => '2025-12-31'],
            ['name' => 'CardCash', 'client_relationship' => 'good', 'team_count' => 2, 'products' => ['ads'], 'kpi' => 'PPC', 'status' => 'renewal', 'partner' => true, 'start' => '2025-02-15', 'end' => $this->resolveEndDate('2025-02-15', 'Feb 14')],
            ['name' => 'Sellify', 'team_count' => 1, 'products' => ['seo'], 'kpi' => 'Organic Traffic, C...', 'status' => 'renewal', 'start' => '2025-10-01', 'end' => $this->resolveEndDate('2025-10-01', 'Mar 31')],
            ['name' => 'Faye', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['ads'], 'kpi' => 'PPC', 'status' => 'renewal', 'start' => '2025-04-15', 'end' => $this->resolveEndDate('2025-04-15', 'Apr 14')],
            ['name' => 'PeopleFinders', 'team_count' => 1, 'products' => ['seo', 'ads'], 'kpi' => 'SEO, Content', 'status' => 'renewal', 'start' => '2025-11-01', 'end' => $this->resolveEndDate('2025-11-01', 'Apr 30')],
            ['name' => 'Prescient AI', 'client_relationship' => 'good', 'team_count' => 2, 'products' => ['ads'], 'kpi' => 'PPC', 'status' => 'expired', 'start' => '2025-06-01', 'end' => $this->resolveEndDate('2025-06-01', 'May 31')],
            ['name' => 'Pro Athlete', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Organic Traffic', 'status' => 'renewal', 'start' => '2025-06-01', 'end' => $this->resolveEndDate('2025-06-01', 'May 31')],
            ['name' => 'CPS HR', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Organic Traffic', 'status' => 'renewal', 'partner' => true, 'start' => '2025-07-01', 'end' => $this->resolveEndDate('2025-07-01', 'Jun 30')],
            ['name' => 'Acrisure', 'client_relationship' => 'good', 'team_count' => 1, 'products' => ['seo', 'content'], 'kpi' => 'Deliverables', 'status' => 'renewal', 'partner' => true, 'start' => '2025-01-01', 'end' => $this->resolveEndDate('2025-01-01', 'Jun 30')],
            ['name' => 'Marksmen', 'client_relationship' => 'alert', 'team_count' => 1, 'products' => ['ads'], 'kpi' => 'Cost Per Lead', 'status' => 'expired', 'start' => '2025-07-18', 'end' => $this->resolveEndDate('2025-07-18', 'Jun 30')],
            ['name' => 'KORE', 'client_relationship' => 'good', 'team_count' => 2, 'products' => ['seo'], 'kpi' => 'Organic Traffic', 'status' => 'expired', 'start' => '2025-08-01', 'end' => $this->resolveEndDate('2025-08-01', 'Jul 31')],
        ];
    }
}
