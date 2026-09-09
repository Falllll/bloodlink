<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;
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
            $donorId = DB::table('donors')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'donor_number' => sprintf('DEV-%03d', $index + 1),
                'registered_facility_id' => $facilityId,
                'full_name' => fake()->name(),
                'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
                'sex' => fake()->randomElement(['male', 'female']),
                'blood_group' => fake()->randomElement(['A', 'B', 'AB', 'O']),
                'rh_factor' => fake()->randomElement(['positive', 'negative']),
                'phone' => fake()->numerify('08##########'),
                'email' => fake()->safeEmail(),
                'address' => fake()->address(),
                'city' => fake()->randomElement(['Jakarta', 'Bekasi', 'Tangerang', 'Depok', 'Bogor']),
                'weight_kg' => fake()->randomFloat(2, 45, 110),
                'last_donation_date' => now()->subDays(fake()->numberBetween(30, 180))->toDateString(),
                'donation_count' => fake()->numberBetween(1, 8),
                'is_deferred' => false,
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

        $components = [
            'whole_blood' => 35,
            'packed_red_cells' => 35,
            'fresh_frozen_plasma' => 365,
            'platelet_concentrate' => 5,
            'cryoprecipitate' => 365,
        ];
        $statuses = ['quarantined', 'testing', 'released', 'reserved', 'issued', 'discarded'];

        for ($index = 0; $index < 60; $index++) {
            $component = fake()->randomElement(array_keys($components));
            if ($index < 15) {
                $daysUntilExpiry = fake()->numberBetween(-30, -1);
            } elseif ($index < 30) {
                $daysUntilExpiry = fake()->numberBetween(1, 7);
            } else {
                $daysUntilExpiry = fake()->numberBetween(8, 180);
            }
            $expiresAt = now()->addDays($daysUntilExpiry);
            $facilityId = $facilities[$index % $facilities->count()];

            DB::table('blood_batches')->insert([
                'public_id' => (string) Str::uuid(),
                'batch_number' => sprintf('DEV-BB-%05d', $index + 1),
                'donor_id' => $donors[$index % $donors->count()],
                'facility_id' => $facilityId,
                'component' => $component,
                'blood_group' => fake()->randomElement(['A', 'B', 'AB', 'O']),
                'rh_factor' => fake()->randomElement(['positive', 'negative']),
                'volume_ml' => fake()->numberBetween(200, 500),
                'status' => fake()->randomElement($statuses),
                'collected_at' => (clone $expiresAt)->subDays($components[$component]),
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
