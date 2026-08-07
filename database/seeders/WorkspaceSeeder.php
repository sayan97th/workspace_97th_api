<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    // Deliberately fires model events (unlike most seeders): navigation items
    // rely on the `creating` hook from HasRandomBigId to assign their id.

    /**
     * The workspace catalog shown in the switcher / "Browse all" modal.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $workspaces = [
        ['name' => 'Fulfillment', 'slug' => 'fulfillment', 'mono' => '97', 'color' => '#e53e2e', 'product' => 'Workspace 97th', 'is_home' => true],
        ['name' => 'BASE', 'slug' => 'base', 'mono' => 'B', 'color' => '#2f6fed', 'product' => 'Workspace 97th'],
        ['name' => 'CRM', 'slug' => 'crm', 'mono' => 'C', 'color' => '#4cc3e0', 'product' => 'Sales CRM'],
        ['name' => 'Decision Priority Matrix', 'slug' => 'decision-priority-matrix', 'mono' => 'D', 'color' => '#6b7280', 'product' => 'Workspace 97th'],
        ['name' => 'Highrise', 'slug' => 'highrise', 'mono' => 'H', 'color' => '#26312f', 'product' => 'Workspace 97th'],
        ['name' => 'Partnerships', 'slug' => 'partnerships', 'mono' => 'P', 'color' => '#2f9e68', 'product' => 'Workspace 97th'],
        ['name' => 'Personal', 'slug' => 'personal', 'mono' => 'P', 'color' => '#e8a317', 'product' => 'Workspace 97th'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $position = 0;
        foreach ($this->workspaces as $data) {
            Workspace::firstOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['position' => $position++]),
            );
        }

        $fulfillment = Workspace::where('slug', 'fulfillment')->firstOrFail();

        // Enroll every existing user in the home workspace so any login sees it.
        $membership = ['role' => 'member', 'is_recent' => true];
        User::query()->each(function (User $user) use ($fulfillment, $membership) {
            $fulfillment->users()->syncWithoutDetaching([$user->id => $membership]);
        });

        // Rebuild the Fulfillment navigation tree from scratch (idempotent).
        $fulfillment->navigationItems()->withTrashed()->forceDelete();
        $this->createNodes($fulfillment, $this->fulfillmentTree(), null);

        // Every other seeded workspace has no tree of its own, but still needs
        // a "Manage Workspace" entry point — Fulfillment's own copy comes from
        // the first node of fulfillmentTree() above.
        foreach ($this->workspaces as $data) {
            if ($data['slug'] === 'fulfillment') {
                continue;
            }

            $this->ensureManageWorkspaceItem(Workspace::where('slug', $data['slug'])->firstOrFail());
        }
    }

    /**
     * Idempotently gives a workspace a root "Manage Workspace" leaf.
     */
    private function ensureManageWorkspaceItem(Workspace $workspace): void
    {
        if ($workspace->navigationItems()->where('view_key', 'workspace_manage')->exists()) {
            return;
        }

        $workspace->navigationItems()->create([
            'parent_id' => null,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => 'Manage Workspace',
            'slug' => 'manage-workspace',
            'icon' => 'home',
            'view_key' => 'workspace_manage',
            'is_favorite' => false,
            'position' => 0,
        ]);
    }

    /**
     * Recursively persist a list of tree nodes under the given parent.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function createNodes(Workspace $workspace, array $nodes, ?int $parent_id): void
    {
        foreach ($nodes as $position => $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);

            /** @var WorkspaceNavigationItem $item */
            $item = $workspace->navigationItems()->create(array_merge([
                'parent_id' => $parent_id,
                'type' => WorkspaceNavigationItem::TYPE_LEAF,
                'is_favorite' => false,
                'position' => $position,
            ], $node));

            if ($children !== []) {
                $this->createNodes($workspace, $children, $item->id);
            }
        }
    }

    /**
     * The Fulfillment navigation tree, mirroring the frontend's static mockup.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fulfillmentTree(): array
    {
        $group = WorkspaceNavigationItem::TYPE_GROUP;
        $leaf = WorkspaceNavigationItem::TYPE_LEAF;

        return [
            ['type' => $leaf, 'label' => 'Manage Workspace', 'slug' => 'manage-workspace', 'icon' => 'home', 'view_key' => 'workspace_manage'],
            ['type' => $leaf, 'label' => 'Client Hub', 'slug' => 'client-hub', 'is_favorite' => true, 'display_style' => 'table', 'board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN],
            ['type' => $group, 'label' => '97th Floor Development', 'slug' => 'development', 'children' => [
                ['type' => $leaf, 'label' => 'Palomar Roadmap & Software Engineering', 'slug' => 'palomar'],
                ['type' => $leaf, 'label' => '97th Floor Web Dev', 'slug' => 'web-dev'],
                ['type' => $group, 'label' => '97th Dev', 'slug' => '97th-dev', 'children' => [
                    ['type' => $leaf, 'label' => 'Sprints', 'slug' => 'sprints'],
                    ['type' => $leaf, 'label' => 'Roadmap', 'slug' => 'roadmap'],
                    ['type' => $leaf, 'label' => 'Bugs Queue', 'slug' => 'bugs-queue'],
                    ['type' => $leaf, 'label' => 'Retrospectives', 'slug' => 'retrospectives'],
                ]],
            ]],
            ['type' => $group, 'label' => 'Fulfillment', 'slug' => 'fulfillment', 'children' => [
                ['type' => $leaf, 'label' => 'Team Blake', 'slug' => 'team-blake'],
                ['type' => $leaf, 'label' => 'Team Jaecie', 'slug' => 'team-jaecie'],
            ]],
            ['type' => $group, 'label' => 'Sales', 'slug' => 'sales', 'children' => [
                ['type' => $leaf, 'label' => 'Sales Resources', 'slug' => 'sales-resources'],
            ]],
            ['type' => $leaf, 'label' => 'Program Development', 'slug' => 'program-development', 'display_style' => 'group'],
            ['type' => $group, 'label' => '97F Marketing Team', 'slug' => 'marketing-team', 'children' => [
                ['type' => $leaf, 'label' => 'BASE Marketing Production Calendar', 'slug' => 'base-marketing-calendar'],
            ]],
            ['type' => $group, 'label' => 'SEO Specialists Portfolios', 'slug' => 'seo-portfolios', 'children' => [
                ['type' => $leaf, 'label' => 'SEO Portfolio Template', 'slug' => 'seo-portfolio-template'],
            ]],
            ['type' => $group, 'label' => 'Creative processes', 'slug' => 'creative-processes', 'children' => [
                ['type' => $group, 'label' => 'Creative Processes', 'slug' => 'creative-processes-inner', 'children' => [
                    ['type' => $leaf, 'label' => 'Creative Processes', 'slug' => 'creative-processes-leaf'],
                    ['type' => $leaf, 'label' => 'Asset Library (DAM)', 'slug' => 'asset-library-dam'],
                ]],
            ]],
        ];
    }
}
