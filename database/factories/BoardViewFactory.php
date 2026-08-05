<?php

namespace Database\Factories;

use App\Enums\BoardViewType;
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
            'view_type' => BoardViewType::Table->value,
            'position' => 0,
            'is_primary' => true,
            'row_height' => 'single',
        ];
    }

    /**
     * A Kanban-kind tab instead of the default table.
     */
    public function kanban(): static
    {
        return $this->state(fn (array $attributes) => [
            'label' => 'Kanban',
            'view_type' => BoardViewType::Kanban->value,
            'is_primary' => false,
        ]);
    }
}
