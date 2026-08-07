<?php

namespace Database\Factories;

use App\Models\AccountTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountTeam>
 */
class AccountTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Team',
        ];
    }
}
