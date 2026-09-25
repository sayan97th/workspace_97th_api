<?php

namespace App\Support;

use App\Models\BoardColumn;
use App\Services\Board\BoardTemplateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The ready made boards of the Template center, written as snapshots in the
 * format of {@see BoardTemplateService}. Each one has a Table tab with sample
 * items plus tabs that read it (Dashboard, Workload, Chart), since in this
 * app a new Table or Kanban tab would hold items of its own. Dates are
 * relative to today so the sample work always looks current.
 */
class BuiltInBoardTemplates
{
    public const CATEGORIES = [
        'project_management' => 'Project management',
        'marketing' => 'Marketing',
        'sales_crm' => 'Sales and CRM',
        'software' => 'Software development',
        'operations' => 'Operations',
        'hr' => 'HR',
        'custom' => 'Saved by your team',
    ];

    private const GREEN = '#00c875';

    private const ORANGE = '#fdab3d';

    private const RED = '#e2445c';

    private const BLUE = '#579bfc';

    private const PURPLE = '#a25ddc';

    private const GREY = '#c4c4c4';

    private const DARK_BLUE = '#0086c0';

    /**
     * @return array<int, array{key: string, name: string, description: string, category: string, color: string, snapshot: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            self::projectManagement(),
            self::productRoadmap(),
            self::marketingCampaign(),
            self::contentCalendar(),
            self::salesPipeline(),
            self::bugTracking(),
            self::eventPlanning(),
            self::employeeOnboarding(),
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, category: string, color: string, snapshot: array<string, mixed>}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    private static function projectManagement(): array
    {
        return self::template('project_management', 'Project management', 'Plan projects, assign owners, track status and deadlines, and see who is overloaded.', 'project_management', self::BLUE, [
            'owner' => ['Owner', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['Not started', self::GREY], ['Working on it', self::ORANGE], ['Stuck', self::RED], ['Done', self::GREEN]]],
            'priority' => ['Priority', BoardColumn::TYPE_STATUS, 130, [['Critical', '#333333'], ['High', '#401694'], ['Medium', '#5559df'], ['Low', self::BLUE]]],
            'timeline' => ['Timeline', BoardColumn::TYPE_TIMELINE, 180],
            'effort' => ['Estimated hours', BoardColumn::TYPE_NUMBER, 130],
        ], [
            ['This week', self::BLUE, [
                ['Kickoff meeting with stakeholders', ['status' => 'Done', 'priority' => 'High', 'timeline' => [-3, -3], 'effort' => 2]],
                ['Define project scope', ['status' => 'Working on it', 'priority' => 'Critical', 'timeline' => [-1, 2], 'effort' => 8]],
                ['Set up project workspace', ['status' => 'Working on it', 'priority' => 'Medium', 'timeline' => [0, 1], 'effort' => 3]],
            ]],
            ['Next week', self::PURPLE, [
                ['Draft project plan', ['status' => 'Not started', 'priority' => 'High', 'timeline' => [5, 8], 'effort' => 10]],
                ['Review budget', ['status' => 'Not started', 'priority' => 'Medium', 'timeline' => [7, 7], 'effort' => 4]],
            ]],
            ['Completed', self::GREEN, [
                ['Gather requirements', ['status' => 'Done', 'priority' => 'High', 'timeline' => [-10, -6], 'effort' => 12]],
            ]],
        ], ['dashboard', 'workload']);
    }

    private static function productRoadmap(): array
    {
        return self::template('product_roadmap', 'Product roadmap', 'Plan features by quarter, estimate effort and follow each release from idea to launch.', 'software', self::PURPLE, [
            'owner' => ['Product owner', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['Planned', self::GREY], ['In progress', self::ORANGE], ['At risk', self::RED], ['Released', self::GREEN]]],
            'quarter' => ['Quarter', BoardColumn::TYPE_DROPDOWN, 150, [['Q1', self::BLUE], ['Q2', self::PURPLE], ['Q3', self::ORANGE], ['Q4', self::GREEN]]],
            'timeline' => ['Timeline', BoardColumn::TYPE_TIMELINE, 180],
            'effort' => ['Story points', BoardColumn::TYPE_NUMBER, 120],
        ], [
            ['Now', self::ORANGE, [
                ['Single sign on', ['status' => 'In progress', 'quarter' => ['Q1'], 'timeline' => [-7, 14], 'effort' => 13]],
                ['Mobile notifications', ['status' => 'At risk', 'quarter' => ['Q1'], 'timeline' => [0, 21], 'effort' => 8]],
            ]],
            ['Next', self::BLUE, [
                ['Public API v2', ['status' => 'Planned', 'quarter' => ['Q2'], 'timeline' => [30, 75], 'effort' => 21]],
                ['Custom reports', ['status' => 'Planned', 'quarter' => ['Q2'], 'timeline' => [45, 90], 'effort' => 13]],
            ]],
            ['Released', self::GREEN, [
                ['Dark mode', ['status' => 'Released', 'quarter' => ['Q1'], 'timeline' => [-40, -20], 'effort' => 5]],
            ]],
        ], ['dashboard', 'workload']);
    }

    private static function marketingCampaign(): array
    {
        return self::template('marketing_campaign', 'Marketing campaign', 'Coordinate campaign tasks across channels and keep the budget in check.', 'marketing', self::RED, [
            'owner' => ['Owner', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['Planning', self::GREY], ['In progress', self::ORANGE], ['Waiting for review', self::PURPLE], ['Live', self::GREEN]]],
            'channel' => ['Channel', BoardColumn::TYPE_DROPDOWN, 170, [['Email', self::BLUE], ['Social', self::PURPLE], ['Paid ads', self::ORANGE], ['Website', self::GREEN]]],
            'budget' => ['Budget', BoardColumn::TYPE_NUMBER, 120],
            'launch' => ['Launch date', BoardColumn::TYPE_DATE, 140],
        ], [
            ['Pre launch', self::PURPLE, [
                ['Campaign brief', ['status' => 'In progress', 'channel' => ['Website'], 'budget' => 0, 'launch' => 3]],
                ['Design ad creatives', ['status' => 'Waiting for review', 'channel' => ['Paid ads', 'Social'], 'budget' => 1500, 'launch' => 7]],
                ['Write newsletter', ['status' => 'Planning', 'channel' => ['Email'], 'budget' => 200, 'launch' => 10]],
            ]],
            ['Launch', self::ORANGE, [
                ['Publish landing page', ['status' => 'Planning', 'channel' => ['Website'], 'budget' => 500, 'launch' => 14]],
                ['Start paid campaign', ['status' => 'Planning', 'channel' => ['Paid ads'], 'budget' => 5000, 'launch' => 14]],
            ]],
        ], ['dashboard', 'chart']);
    }

    private static function contentCalendar(): array
    {
        return self::template('content_calendar', 'Content calendar', 'Plan, write and publish content on a shared schedule.', 'marketing', self::ORANGE, [
            'writer' => ['Writer', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['Idea', self::GREY], ['Writing', self::ORANGE], ['Editing', self::PURPLE], ['Scheduled', self::BLUE], ['Published', self::GREEN]]],
            'publish' => ['Publish date', BoardColumn::TYPE_DATE, 140],
            'channel' => ['Channel', BoardColumn::TYPE_DROPDOWN, 160, [['Blog', self::BLUE], ['Newsletter', self::ORANGE], ['LinkedIn', self::DARK_BLUE], ['YouTube', self::RED]]],
            'link' => ['Link', BoardColumn::TYPE_LINK, 160],
        ], [
            ['This month', self::BLUE, [
                ['How we plan our quarter', ['status' => 'Writing', 'publish' => 4, 'channel' => ['Blog']]],
                ['Customer story: onboarding', ['status' => 'Editing', 'publish' => 6, 'channel' => ['Blog', 'LinkedIn']]],
                ['Monthly product update', ['status' => 'Scheduled', 'publish' => 9, 'channel' => ['Newsletter']]],
            ]],
            ['Ideas', self::GREY, [
                ['Behind the scenes video', ['status' => 'Idea', 'channel' => ['YouTube']]],
                ['Five tips for remote teams', ['status' => 'Idea', 'channel' => ['Blog']]],
            ]],
        ], ['dashboard']);
    }

    private static function salesPipeline(): array
    {
        return self::template('sales_pipeline', 'Sales pipeline', 'Track every deal from first contact to closed, with values and close dates.', 'sales_crm', self::GREEN, [
            'owner' => ['Deal owner', BoardColumn::TYPE_PEOPLE, 140],
            'stage' => ['Stage', BoardColumn::TYPE_STATUS, 160, [['Lead', self::GREY], ['Contacted', self::BLUE], ['Proposal sent', self::PURPLE], ['Negotiation', self::ORANGE], ['Won', self::GREEN], ['Lost', self::RED]]],
            'value' => ['Deal value', BoardColumn::TYPE_NUMBER, 130],
            'close' => ['Expected close', BoardColumn::TYPE_DATE, 140],
            'email' => ['Contact email', BoardColumn::TYPE_EMAIL, 190],
            'phone' => ['Phone', BoardColumn::TYPE_PHONE, 140],
        ], [
            ['Active deals', self::BLUE, [
                ['Acme Corp renewal', ['stage' => 'Negotiation', 'value' => 24000, 'close' => 12, 'email' => 'buyer@acme.example']],
                ['Globex expansion', ['stage' => 'Proposal sent', 'value' => 18500, 'close' => 20, 'email' => 'ops@globex.example']],
                ['Initech pilot', ['stage' => 'Contacted', 'value' => 6000, 'close' => 35]],
                ['Umbrella inbound lead', ['stage' => 'Lead', 'value' => 9000, 'close' => 45]],
            ]],
            ['Closed', self::GREEN, [
                ['Stark Industries', ['stage' => 'Won', 'value' => 42000, 'close' => -5]],
                ['Wayne Enterprises', ['stage' => 'Lost', 'value' => 15000, 'close' => -12]],
            ]],
        ], ['dashboard', 'chart']);
    }

    private static function bugTracking(): array
    {
        return self::template('bug_tracking', 'Bug tracking', 'Report, prioritize and fix bugs, and see where each one stands.', 'software', self::RED, [
            'assignee' => ['Assignee', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['New', self::GREY], ['In progress', self::ORANGE], ['In review', self::PURPLE], ['Fixed', self::GREEN], ['Will not fix', '#784bd1']]],
            'severity' => ['Severity', BoardColumn::TYPE_STATUS, 130, [['Critical', self::RED], ['High', self::ORANGE], ['Medium', self::BLUE], ['Low', self::GREY]]],
            'environment' => ['Environment', BoardColumn::TYPE_DROPDOWN, 160, [['Production', self::RED], ['Staging', self::ORANGE], ['Development', self::BLUE]]],
            'reported' => ['Reported on', BoardColumn::TYPE_DATE, 140],
        ], [
            ['Open', self::RED, [
                ['Checkout fails on Safari', ['status' => 'In progress', 'severity' => 'Critical', 'environment' => ['Production'], 'reported' => -1]],
                ['Wrong currency in invoices', ['status' => 'New', 'severity' => 'High', 'environment' => ['Production'], 'reported' => -2]],
                ['Tooltip hidden behind modal', ['status' => 'In review', 'severity' => 'Low', 'environment' => ['Staging'], 'reported' => -4]],
            ]],
            ['Resolved', self::GREEN, [
                ['Login loop after password reset', ['status' => 'Fixed', 'severity' => 'High', 'environment' => ['Production'], 'reported' => -9]],
            ]],
        ], ['dashboard']);
    }

    private static function eventPlanning(): array
    {
        return self::template('event_planning', 'Event planning', 'Organize venues, vendors and tasks for your next event.', 'operations', self::PURPLE, [
            'owner' => ['Owner', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['To do', self::GREY], ['Booked', self::BLUE], ['Confirmed', self::GREEN], ['Blocked', self::RED]]],
            'due' => ['Due date', BoardColumn::TYPE_DATE, 140],
            'cost' => ['Cost', BoardColumn::TYPE_NUMBER, 120],
            'vendor' => ['Vendor', BoardColumn::TYPE_TEXT, 170],
        ], [
            ['Venue and logistics', self::BLUE, [
                ['Book the venue', ['status' => 'Confirmed', 'due' => -2, 'cost' => 3500, 'vendor' => 'City Hall']],
                ['Arrange catering', ['status' => 'Booked', 'due' => 10, 'cost' => 2200, 'vendor' => 'Green Kitchen']],
                ['Audio and video setup', ['status' => 'To do', 'due' => 15, 'cost' => 900]],
            ]],
            ['Promotion', self::ORANGE, [
                ['Send invitations', ['status' => 'To do', 'due' => 5, 'cost' => 0]],
                ['Print badges', ['status' => 'Blocked', 'due' => 18, 'cost' => 150, 'vendor' => 'PrintCo']],
            ]],
        ], ['dashboard']);
    }

    private static function employeeOnboarding(): array
    {
        return self::template('employee_onboarding', 'Employee onboarding', 'Give every new hire a smooth first month, step by step.', 'hr', self::GREEN, [
            'buddy' => ['Buddy', BoardColumn::TYPE_PEOPLE, 140],
            'status' => ['Status', BoardColumn::TYPE_STATUS, 150, [['Not started', self::GREY], ['In progress', self::ORANGE], ['Done', self::GREEN]]],
            'department' => ['Department', BoardColumn::TYPE_DROPDOWN, 160, [['Engineering', self::BLUE], ['Sales', self::GREEN], ['Marketing', self::PURPLE], ['Operations', self::ORANGE]]],
            'due' => ['Due date', BoardColumn::TYPE_DATE, 140],
            'notes' => ['Notes', BoardColumn::TYPE_LONG_TEXT, 200],
        ], [
            ['Before day one', self::PURPLE, [
                ['Send welcome email', ['status' => 'Done', 'due' => -3]],
                ['Order laptop', ['status' => 'In progress', 'due' => 1]],
                ['Create accounts', ['status' => 'Not started', 'due' => 2]],
            ]],
            ['First week', self::BLUE, [
                ['Team introductions', ['status' => 'Not started', 'due' => 5]],
                ['Security training', ['status' => 'Not started', 'due' => 7]],
            ]],
            ['First month', self::GREEN, [
                ['30 day check in', ['status' => 'Not started', 'due' => 30]],
            ]],
        ], ['dashboard']);
    }

    /**
     * Builds a snapshot from a compact description.
     *
     * Columns are `key => [label, type, width, options?]`, where options are
     * `[label, color]` pairs for Status and Dropdown columns. Item values are
     * keyed by column key: an option label for Status, a list of option labels
     * for Dropdown, a day offset from today for Date, a `[start, end]` pair of
     * day offsets for Timeline, the raw value for everything else.
     *
     * @param  array<string, array<int, mixed>>  $columns
     * @param  array<int, array{0: string, 1: string, 2: array<int, array{0: string, 1: array<string, mixed>}>}>  $groups
     * @param  array<int, string>  $extra_views  any of `dashboard`, `workload`, `chart`
     * @return array{key: string, name: string, description: string, category: string, color: string, snapshot: array<string, mixed>}
     */
    private static function template(string $key, string $name, string $description, string $category, string $color, array $columns, array $groups, array $extra_views): array
    {
        $today = Carbon::today();
        $ref = 1;
        $column_refs = [];
        $option_ids = [];
        $snapshot_columns = [];

        $position = 0;
        foreach ($columns as $column_key => [$label, $type, $width]) {
            $column_refs[$column_key] = (string) $ref++;
            $options = $columns[$column_key][3] ?? null;
            $config = null;
            if ($options !== null) {
                $config = ['options' => array_map(function (array $option) use ($column_key, &$option_ids) {
                    $id = $column_key.'_'.Str::slug($option[0], '_');
                    $option_ids[$column_key][$option[0]] = $id;

                    return ['id' => $id, 'label' => $option[0], 'color' => $option[1], 'is_active' => true];
                }, $options)];
            }

            $snapshot_columns[] = [
                'ref' => $column_refs[$column_key],
                'key' => $column_key,
                'label' => $label,
                'type' => $type,
                'scope' => BoardColumn::SCOPE_ITEM,
                'position' => $position++,
                'width' => $width,
                'config' => $config,
            ];
        }

        $snapshot_groups = [];
        foreach ($groups as $group_index => [$group_name, $group_color, $items]) {
            $snapshot_groups[] = [
                'ref' => (string) $ref++,
                'name' => $group_name,
                'accent_color' => $group_color,
                'position' => $group_index,
                'items' => array_map(function (array $item) use ($columns, $column_refs, $option_ids, $today) {
                    $values = [];
                    foreach ($item[1] as $column_key => $value) {
                        $type = $columns[$column_key][1];
                        $values[$column_refs[$column_key]] = match ($type) {
                            BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL => $option_ids[$column_key][$value] ?? null,
                            BoardColumn::TYPE_DROPDOWN => array_values(array_map(fn (string $label) => $option_ids[$column_key][$label], $value)),
                            BoardColumn::TYPE_DATE => $today->copy()->addDays($value)->toDateString(),
                            // The table's Timeline cell writes the `start..end` string form.
                            BoardColumn::TYPE_TIMELINE => $today->copy()->addDays($value[0])->toDateString().'..'.$today->copy()->addDays($value[1])->toDateString(),
                            default => $value,
                        };
                    }

                    return ['name' => $item[0], 'values' => $values, 'children' => []];
                }, $items),
            ];
        }

        $main_ref = (string) $ref++;
        $views = [[
            'ref' => $main_ref,
            'label' => 'Main table',
            'view_type' => 'table',
            'is_primary' => true,
            'row_height' => 'single',
            'columns' => $snapshot_columns,
            'groups' => $snapshot_groups,
        ]];

        $status_key = self::firstKeyOfType($columns, BoardColumn::TYPE_STATUS);
        $people_key = self::firstKeyOfType($columns, BoardColumn::TYPE_PEOPLE);
        $number_key = self::firstKeyOfType($columns, BoardColumn::TYPE_NUMBER);
        $date_key = self::firstKeyOfType($columns, BoardColumn::TYPE_TIMELINE) ?? self::firstKeyOfType($columns, BoardColumn::TYPE_DATE);

        foreach ($extra_views as $extra_view) {
            $views[] = match ($extra_view) {
                'dashboard' => [
                    'ref' => (string) $ref++,
                    'label' => 'Dashboard',
                    'view_type' => 'dashboard',
                    'dashboard_config' => ['widgets' => array_values(array_filter([
                        ['id' => 'w1', 'type' => 'numbers', 'title' => 'Items', 'width' => 1, 'source_view_id' => (int) $main_ref, 'config' => ['function' => 'count']],
                        $status_key ? ['id' => 'w2', 'type' => 'battery', 'title' => 'Progress', 'width' => 2, 'source_view_id' => (int) $main_ref, 'config' => ['status_column_id' => $column_refs[$status_key]]] : null,
                        $status_key ? ['id' => 'w3', 'type' => 'chart', 'title' => 'Items by status', 'width' => 1, 'source_view_id' => (int) $main_ref, 'config' => ['chart_type' => 'donut', 'group_by_column_id' => $column_refs[$status_key], 'aggregate_fn' => 'count']] : null,
                        $number_key ? ['id' => 'w4', 'type' => 'numbers', 'title' => 'Total', 'width' => 1, 'source_view_id' => (int) $main_ref, 'config' => ['function' => 'sum', 'column_id' => $column_refs[$number_key]]] : null,
                        $people_key ? ['id' => 'w5', 'type' => 'workload', 'title' => 'Workload', 'width' => 1, 'source_view_id' => (int) $main_ref, 'config' => ['people_column_id' => $column_refs[$people_key]]] : null,
                        ['id' => 'w6', 'type' => 'table', 'title' => 'Items overview', 'width' => 3, 'source_view_id' => (int) $main_ref, 'config' => ['limit' => 10]],
                    ]))],
                    'columns' => [],
                    'groups' => [],
                ],
                'workload' => [
                    'ref' => (string) $ref++,
                    'label' => 'Workload',
                    'view_type' => 'workload',
                    'workload_config' => [
                        'source_view_id' => (int) $main_ref,
                        'people_column_id' => $people_key ? $column_refs[$people_key] : null,
                        'date_column_id' => $date_key ? $column_refs[$date_key] : null,
                        'effort_column_id' => $number_key ? $column_refs[$number_key] : null,
                        'bucket' => 'week',
                    ],
                    'columns' => [],
                    'groups' => [],
                ],
                'chart' => [
                    'ref' => (string) $ref++,
                    'label' => 'Chart',
                    'view_type' => 'chart',
                    'chart_config' => [
                        'chart_type' => 'bar',
                        'source_view_id' => (int) $main_ref,
                        'group_by_column_id' => $status_key ? $column_refs[$status_key] : '__group__',
                        'aggregate_fn' => $number_key ? 'sum' : 'count',
                        'value_column_id' => $number_key ? $column_refs[$number_key] : null,
                    ],
                    'columns' => [],
                    'groups' => [],
                ],
            };
        }

        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'color' => $color,
            'snapshot' => ['version' => BoardTemplateService::SNAPSHOT_VERSION, 'board' => ['item_column_label' => null], 'tags' => [], 'views' => $views],
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $columns
     */
    private static function firstKeyOfType(array $columns, string $type): ?string
    {
        foreach ($columns as $key => $column) {
            if ($column[1] === $type) {
                return $key;
            }
        }

        return null;
    }
}
