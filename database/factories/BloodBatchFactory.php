<?php

namespace Database\Factories;

use App\Models\BloodBatch;
use App\Models\Donor;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BloodBatch>
 */
class BloodBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $component = fake()->randomElement([
            'whole_blood', 'packed_red_cells', 'fresh_frozen_plasma',
            'platelet_concentrate', 'cryoprecipitate']);

        $shelflifeDays = match ($component) {
            'platelet_concentrate' => 5,
            'fresh_frozen_plasma', 'cryoprecipitate' => 365,
            default => 35,
        };

        $collectedAt = fake()->dateTimeBetween('-60 Days', 'now');

        $bloodGroup = fake()->randomElement(['A', 'B', 'AB', 'O']);

        return [
            'public_id' => (string) Str::uuid(),
            'batch_number' => fake()->unique()->bothify('BB-########'),
            'donor_id' => Donor::factory(),
            'facility_id' => Facility::factory(),
            'component' => $component,
            'blood_group' => $bloodGroup,
            'rh_factor' => fake()->randomElement(['positive', 'negative']),
            'volume_ml' => fake()->numberBetween(200, 500),
            'collected_at' => $collectedAt,
            'expires_at' => (clone $collectedAt)->modify("+{$shelflifeDays} days"),
        ];
    }

    public function released(): static
    {
        return $this->state(['status' => 'released']);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->addDays(2),
        ]);
    }
}
