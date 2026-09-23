<?php

namespace Database\Factories;

use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DeferralType;
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
            'phone' => fake()->unique()->numerify('08##########'),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
        ];
    }

    public function eligible(): static
    {
        return $this->state([
            'last_donation_date' => now()->subDays(90),
        ]);
    }

    public function deferred(): static
    {
        return $this->afterCreating(function (Donor $donor): void {
            $reason = DeferralReason::query()->where('type', 'temporary')->where('is_active', true)->firstOrFail();

            $deferral = new Deferral([
                'donor_id' => $donor->id,
                'deferral_reason_id' => $reason->id,
                'type' => DeferralType::TEMPORARY,
                'anchor_at' => now(),
                'ends_at' => now()->addDays(30),
                'source' => DeferralSource::MANUAL,
            ]);

            $deferral->forceFill([
                'public_id' => (string) Str::uuid(),
                'facility_id' => $donor->registered_facility_id,
            ])->save();
        });
    }
}
