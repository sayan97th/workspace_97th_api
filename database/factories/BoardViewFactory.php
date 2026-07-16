<?php

namespace Database\Factories;

use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardView>
 */
class BoardViewFactory extends Factory
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
            'label' => 'Main table',
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ];
    }
}
