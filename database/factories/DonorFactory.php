<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
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
            'donor_number' => fake()->unique()->bothify('D-########'),
            'registered_facility_id' => Facility::factory(),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['male', 'female']),
            'phone' => fake()->numerify('08##########'),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
        ];
    }

    public function eligible(): static
    {
        return $this->state([
            'last_donation_date' => now()->subDays(90),
            'is_deferred' => false,
        ]);
    }

    public function deferred(): static
    {
        return $this->state([
            'is_deferred' => true,
            'deferred_until' => now()->addDays(30),
        ]);
    }
}
