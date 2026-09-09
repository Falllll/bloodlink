<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DonorLocationQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');

        DB::table('donors')->delete();
        DB::table('facilities')->delete();

        $facilityId = DB::table('facilities')->insertGetId([
            'public_id' => DB::raw('gen_random_uuid()::uuid'),
            'code' => 'FAC-001',
            'name' => 'Facility Test',
            'type' => 'hospital',
            'address' => 'Jl. Test 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'phone' => '0210000000',
            'email' => 'test@example.com',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $nearId = DB::table('donors')->insertGetId([
            'public_id' => DB::raw('gen_random_uuid()::uuid'),
            'donor_number' => 'D-NEAR',
            'registered_facility_id' => $facilityId,
            'full_name' => 'Near Donor',
            'date_of_birth' => '1990-01-01',
            'sex' => 'male',
            'phone' => '0811111111',
            'address' => 'Jl. Test 2',
            'city' => 'Jakarta',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $bandungId = DB::table('donors')->insertGetId([
            'public_id' => DB::raw('gen_random_uuid()::uuid'),
            'donor_number' => 'D-BDG',
            'registered_facility_id' => $facilityId,
            'full_name' => 'Bandung Donor',
            'date_of_birth' => '1992-02-02',
            'sex' => 'female',
            'phone' => '0822222222',
            'address' => 'Jl. Test 3',
            'city' => 'Bandung',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $noLocationId = DB::table('donors')->insertGetId([
            'public_id' => DB::raw('gen_random_uuid()::uuid'),
            'donor_number' => 'D-NOLOC',
            'registered_facility_id' => $facilityId,
            'full_name' => 'No Location Donor',
            'date_of_birth' => '1993-03-03',
            'sex' => 'female',
            'phone' => '0833333333',
            'address' => 'Jl. Test 4',
            'city' => 'Jakarta',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        DB::update(
            'UPDATE donors SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
            [106.8272, -6.0950, $nearId]
        );

        DB::update(
            'UPDATE donors SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
            [107.6191, -6.9175, $bandungId]
        );

        // Leave this donor without coordinates so the null-location filter is tested explicitly.
    }

    public function test_postgis_extension_is_enabled(): void
    {
        $version = DB::scalar('SELECT PostGIS_Version()');

        $this->assertNotEmpty($version);
        $this->assertStringContainsString('3.', $version);
    }

    public function test_location_column_uses_gist_index(): void
    {
        $index = DB::scalar("SELECT indexdef FROM pg_indexes WHERE tablename = 'donors' AND indexname = 'donors_location_gist'");

        $this->assertNotNull($index);
        $this->assertStringContainsString('using gist', strtolower((string) $index));
    }

    public function test_within_radius_respects_tiered_distances(): void
    {
        $baseLat = -6.1754;
        $baseLon = 106.8272;

        $fiveKm = DB::table('donors')
            ->whereNotNull('location')
            ->whereRaw('ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [$baseLon, $baseLat, 5000])
            ->count();

        $fifteenKm = DB::table('donors')
            ->whereNotNull('location')
            ->whereRaw('ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [$baseLon, $baseLat, 15000])
            ->count();

        $thirtyKm = DB::table('donors')
            ->whereNotNull('location')
            ->whereRaw('ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [$baseLon, $baseLat, 30000])
            ->count();

        $this->assertSame(0, $fiveKm);
        $this->assertSame(1, $fifteenKm);
        $this->assertSame(1, $thirtyKm);
    }
}
