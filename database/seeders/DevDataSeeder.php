<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ComponentStorageProfile;
use App\Models\Donor;
use App\Models\Facility;
use DateInterval;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $facilities = collect([
            Facility::factory()->state(['type' => 'hospital'])->raw([
                'name' => 'RS Jakarta Pusat',
                'address' => 'Jl. Medan Merdeka Selatan No. 1',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'phone' => '0215000001',
                'email' => 'jakarta@example.com',
            ]),
            Facility::factory()->bloodBank()->raw([
                'name' => 'Bank Darah Bekasi',
                'address' => 'Jl. Ahmad Yani No. 2',
                'city' => 'Bekasi',
                'province' => 'Jawa Barat',
                'phone' => '0215000002',
                'email' => 'bekasi@example.com',
            ]),
            Facility::factory()->state(['type' => 'donation_unit'])->raw([
                'name' => 'Unit Donor Tangerang',
                'address' => 'Jl. Raya Serpong No. 3',
                'city' => 'Tangerang',
                'province' => 'Banten',
                'phone' => '0215000003',
                'email' => 'tangerang@example.com',
            ]),
        ])->map(function (array $attributes) use ($now): int {
            return DB::table('facilities')->insertGetId($attributes + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $donors = collect();
        for ($index = 0; $index < 30; $index++) {
            $facilityId = $facilities[$index % $facilities->count()];
            $phone = fake()->unique()->numerify('08##########');
            $donorId = DB::table('donors')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'donor_number' => sprintf('DEV-%03d', $index + 1),
                'registered_facility_id' => $facilityId,
                'full_name' => fake()->name(),
                'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                'sex' => fake()->randomElement(['male', 'female']),
                'blood_group' => fake()->randomElement(['A', 'B', 'AB', 'O']),
                'rh_factor' => fake()->randomElement(['positive', 'negative']),
                'phone' => Crypt::encryptString($phone),
                'phone_hash' => Donor::phoneHash($phone),
                'email' => Crypt::encryptString(fake()->safeEmail()),
                'address' => Crypt::encryptString(fake()->address()),
                'city' => fake()->randomElement(['Jakarta', 'Bekasi', 'Tangerang', 'Depok', 'Bogor']),
                'weight_kg' => fake()->randomFloat(2, 45, 110),
                'last_donation_date' => now()->subDays(fake()->numberBetween(30, 180))->toDateString(),
                'donation_count' => fake()->numberBetween(1, 8),
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id');

            $donors->push($donorId);

            $latitude = -6.2 + (($index % 6) * 0.035);
            $longitude = 106.75 + (($index % 5) * 0.06);
            DB::update(
                'UPDATE donors SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                [$longitude, $latitude, $donorId]
            );
        }

        // Profil penyimpanan per komponen dari master data. Komponen beku punya dua
        // band, jadi dua masa simpan. Seeder ini juga dipanggil sendirian (SeederSmokeTest).
        if (! ComponentStorageProfile::query()->exists()) {
            $this->call(ComponentTypeSeeder::class);
        }

        $profilesByComponent = ComponentStorageProfile::query()
            ->with('componentType')
            ->get()
            ->groupBy(fn (ComponentStorageProfile $profile): string => $profile->componentType->code->value);
        $statuses = ['quarantined', 'testing', 'released', 'reserved', 'issued', 'discarded'];

        for ($index = 0; $index < 60; $index++) {
            $component = fake()->randomElement($profilesByComponent->keys()->all());
            /** @var ComponentStorageProfile $profile */
            $profile = fake()->randomElement($profilesByComponent[$component]->all());

            if ($index < 15) {
                $daysUntilExpiry = fake()->numberBetween(-30, -1);
            } elseif ($index < 30) {
                $daysUntilExpiry = fake()->numberBetween(1, 7);
            } else {
                $daysUntilExpiry = fake()->numberBetween(8, 180);
            }

            // Mundur dari target kedaluwarsa sejauh masa simpan profil, lalu hitung
            // ulang kedaluwarsanya lewat profil -- asal-usulnya selalu profil itu.
            $collectedAt = now()->addDays($daysUntilExpiry)
                ->sub(new DateInterval($profile->shelf_life_unit->toDateIntervalSpec($profile->shelf_life_value)));
            if ($collectedAt->isFuture()) {
                $collectedAt = now()->subHours(fake()->numberBetween(1, 23));
            }
            $expiresAt = $profile->expiryFrom($collectedAt->toDateTimeImmutable());
            $facilityId = $facilities[$index % $facilities->count()];
            $status = fake()->randomElement($statuses);

            // CHECK blood_batches_released_shape: released/reserved/issued wajib membawa
            // released_at. Data dev sintetis: waktunya setelah pengambilan, tidak di masa depan.
            $releasedAt = in_array($status, ['released', 'reserved', 'issued'], true)
                ? $collectedAt->copy()->addHours(fake()->numberBetween(12, 48))->min($now)
                : null;

            DB::table('blood_batches')->insert([
                'public_id' => (string) Str::uuid(),
                'batch_number' => sprintf('DEV-BB-%05d', $index + 1),
                'donor_id' => $donors[$index % $donors->count()],
                'facility_id' => $facilityId,
                'component' => $component,
                'blood_group' => fake()->randomElement(['A', 'B', 'AB', 'O']),
                'rh_factor' => fake()->randomElement(['positive', 'negative']),
                'volume_ml' => fake()->numberBetween(200, 500),
                'status' => $status,
                'collected_at' => $collectedAt,
                'released_at' => $releasedAt,
                'expires_at' => $expiresAt,
                'storage_profile_id' => $profile->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
