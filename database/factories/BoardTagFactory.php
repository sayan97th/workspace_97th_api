<?php

namespace Database\Factories;

use App\Models\BoardTag;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardTag>
 */
class BoardTagFactory extends Factory
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
            'label' => '#'.fake()->unique()->word(),
            'color' => fake()->hexColor(),
            'position' => 0,
        ];
    }
}
