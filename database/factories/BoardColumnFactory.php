<?php

namespace Database\Factories;

use App\Models\BoardColumn;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardColumn>
 */
class BoardColumnFactory extends Factory
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
            'board_id' => WorkspaceNavigationItem::factory(),
            'key' => Str::slug($label, '_'),
            'label' => $label,
            'type' => BoardColumn::TYPE_TEXT,
            'position' => 0,
            'width' => 180,
            'config' => null,
            'hideable' => true,
            'pinnable' => true,
        ];
    }
}
