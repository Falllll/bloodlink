<?php

namespace Database\Factories;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'code' => fake()->unique()->bothify('FAC-####'),
            'type' => fake()->randomElement(['hospital', 'clinic', 'blood bank']),
        ];
    }

    public function bloodBank(): static
    {
        return $this->state(['type' => 'blood_bank']);
    }
}
