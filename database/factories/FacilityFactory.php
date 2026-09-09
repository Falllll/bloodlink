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
            'name' => fake()->company(),
            'type' => fake()->randomElement(['hospital', 'blood_bank', 'donation_unit', 'mobile_unit']),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->randomElement(['DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'Jawa Timur', 'Bali']),
            'phone' => fake()->numerify('021########'),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function bloodBank(): static
    {
        return $this->state(['type' => 'blood_bank']);
    }
}
