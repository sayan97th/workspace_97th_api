<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceNavigationItem>
 */
class WorkspaceNavigationItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'type' => WorkspaceNavigationItem::TYPE_LEAF,
            'label' => $label,
            'slug' => Str::slug($label),
            'display_style' => 'table',
            'board_type' => WorkspaceNavigationItem::BOARD_TYPE_MAIN,
            'is_favorite' => false,
            'position' => 0,
        ];
    }
}
