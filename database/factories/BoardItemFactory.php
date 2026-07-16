<?php

namespace Database\Factories;

use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItem>
 */
class BoardItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => WorkspaceNavigationItem::factory(),
            'group_id' => BoardGroup::factory(),
            'name' => fake()->sentence(3),
            'position' => 0,
            'created_by_id' => null,
        ];
    }
}
