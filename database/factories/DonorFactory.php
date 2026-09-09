<?php

namespace Database\Factories;

use App\Models\Donor;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'registered_facility_id' => Facility::factory(),
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
