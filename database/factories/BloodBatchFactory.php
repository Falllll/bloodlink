<?php

namespace Database\Factories;

use App\Models\BloodBatch;
use App\Models\ComponentStorageProfile;
use App\Models\Donor;
use App\Models\Facility;
use Database\Seeders\ComponentTypeSeeder;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

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
            // Kedaluwarsa wajib punya asal-usul (CHECK blood_batches_expiry_provenance):
            // diturunkan dari profil default komponennya, bukan konstanta per komponen.
            'storage_profile_id' => fn (array $attributes): int => $this->defaultProfileFor($attributes['component'])->id,
            'expires_at' => fn (array $attributes): DateTimeImmutable => $this->defaultProfileFor($attributes['component'])
                ->expiryFrom(Carbon::parse($attributes['collected_at'])->toDateTimeImmutable()),
        ];
    }

    private function defaultProfileFor(string $component): ComponentStorageProfile
    {
        $query = fn () => ComponentStorageProfile::query()
            ->where('is_default', true)
            ->whereHas('componentType', fn ($q) => $q->where('jurisdiction', 'WHO')->where('code', $component))
            ->first();

        $profile = $query();

        if ($profile === null) {
            // Test DB yang belum di-seed master data komponen: seed sekali, idempoten.
            (new ComponentTypeSeeder)->run();
            $profile = $query();
        }

        if ($profile === null) {
            throw new RuntimeException("No default WHO storage profile for component \"{$component}\".");
        }

        return $profile;
    }

    public function released(): static
    {
        // CHECK blood_batches_released_shape: status released wajib membawa released_at.
        return $this->state(['status' => 'released', 'released_at' => now()]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->addDays(2),
        ]);
    }
}
