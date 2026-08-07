<?php

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Renames the existing "Workspace home" leaf to "Manage Workspace" and
     * routes it through the generic `/boards/{id}` mechanism (dropping its
     * static `href`), then backfills a "Manage Workspace" root leaf onto
     * every workspace that doesn't already have one — previously only the
     * Fulfillment workspace got this item at all.
     */
    public function up(): void
    {
        WorkspaceNavigationItem::withTrashed()
            ->where('view_key', 'workspace_home')
            ->get()
            ->each(function (WorkspaceNavigationItem $item) {
                $item->forceFill([
                    'label' => 'Manage Workspace',
                    'slug' => 'manage-workspace',
                    'view_key' => 'workspace_manage',
                    'href' => null,
                ])->save();
            });

        Workspace::all()->each(function (Workspace $workspace) {
            $has_manage_item = $workspace->navigationItems()
                ->where('view_key', 'workspace_manage')
                ->exists();

            if ($has_manage_item) {
                return;
            }

            // Make room at the front (position is an unsigned column, so
            // shifting existing roots forward is the only way to insert
            // ahead of everything without going negative).
            $workspace->rootNavigationItems()->increment('position');

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
        });
    }

    /**
     * Data backfill only — nothing meaningful to reverse.
     */
    public function down(): void {}
};
