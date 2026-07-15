<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'mono' => strtoupper(fake()->lexify('??')),
            'color' => fake()->hexColor(),
            'product' => 'Work Management',
            'privacy' => 'open',
            'is_home' => false,
            'description' => fake()->sentence(),
            'position' => 0,
        ];
    }
}
