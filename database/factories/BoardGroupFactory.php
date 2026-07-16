<?php

namespace Database\Factories;

use App\Models\BoardGroup;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardGroup>
 */
class BoardGroupFactory extends Factory
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
            'name' => fake()->words(2, true),
            'accent_color' => fake()->randomElement(['#00c875', '#579bfc', '#a25ddc', '#fdab3d', '#e2445c']),
            'position' => 0,
        ];
    }
}
