<?php

namespace Database\Factories;

use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Application\PlaceDeferral;
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


    public function deferred(string $reasonCode = 'TATTOO_PIERCING_ACUPUNCTURE'): static
    {
        return $this->afterCreating(function (Donor $donor) use ($reasonCode): void {
            $reason = DeferralReason::query()
                ->where('jurisdiction', 'WHO')
                ->where('code', $reasonCode)
                ->where('is_active', true)
                ->firstOrFail();

            app(PlaceDeferral::class)->handle(
                donor: $donor,
                reason: $reason,
                anchorAt: now()->toDateTimeImmutable(), 
                source: DeferralSource::MANUAL,
            );
        });
    }
}
