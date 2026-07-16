<?php

namespace Database\Factories;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItemValue>
 */
class BoardItemValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => BoardItem::factory(),
            'column_id' => BoardColumn::factory(),
            'value' => fake()->word(),
        ];
    }
}
