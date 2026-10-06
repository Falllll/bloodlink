<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\ComponentStorageProfile;
use App\Modules\Inventory\Application\AssignStorageProfile;
use App\Modules\Inventory\Application\Exceptions\BatchExpiryUndeterminable;
use App\Modules\Inventory\Domain\ShelfLifeUnit;
use App\Modules\Inventory\Domain\ShelfLifeWindow;
use Database\Seeders\ComponentTypeSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BloodBatchExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ComponentTypeSeeder::class);
    }

    private function unit(string $component, string $collectedAt): BloodBatch
    {
        return new BloodBatch(['component' => $component, 'collected_at' => $collectedAt]);
    }

    public function test_the_same_component_gets_two_expiries_from_two_freezer_bands(): void
    {
        $warm = (new AssignStorageProfile)->handle($this->unit('fresh_frozen_plasma', '2026-01-15 10:00:00'), -22.0);
        $deep = (new AssignStorageProfile)->handle($this->unit('fresh_frozen_plasma', '2026-01-15 10:00:00'), -35.0);

        $this->assertEquals(new DateTimeImmutable('2026-04-15 10:00:00'), $warm->expires_at);
        $this->assertEquals(new DateTimeImmutable('2027-01-15 10:00:00'), $deep->expires_at);
        $this->assertNotSame($warm->storage_profile_id, $deep->storage_profile_id);
    }

    public function test_three_months_is_calendar_months_not_ninety_days(): void
    {
        // +90 hari mendarat di 13 Juni; tiga bulan kalender di 15 Juni.
        $batch = (new AssignStorageProfile)->handle($this->unit('cryoprecipitate', '2026-03-15 08:00:00'), -22.0);

        $this->assertEquals(new DateTimeImmutable('2026-06-15 08:00:00'), $batch->expires_at);
    }

    public function test_a_month_end_collection_is_clamped_to_the_end_of_the_target_month(): void
    {
        // 30 Nov + P3M meluap ke 2 Mar; kedaluwarsa ditarik ke 28 Feb, tidak pernah melewati bulannya.
        $window = new ShelfLifeWindow(new DateTimeImmutable('2026-11-30 08:00:00'), 3, ShelfLifeUnit::MONTHS);

        $this->assertEquals(new DateTimeImmutable('2027-02-28 08:00:00'), $window->expiresAt());
    }

    public function test_a_temperature_outside_every_profile_is_rejected_without_a_fallback(): void
    {
        $this->expectException(BatchExpiryUndeterminable::class);

        // -10 °C: terlalu hangat untuk freezer, terlalu dingin untuk +2…+6. Tidak ada profil.
        (new AssignStorageProfile)->handle($this->unit('fresh_frozen_plasma', '2026-01-15 10:00:00'), -10.0);
    }

    /** @return array<string, mixed> */
    private function rawRow(): array
    {
        $facilityId = DB::table('facilities')->insertGetId([
            'public_id' => (string) Str::uuid(), 'code' => 'F-'.Str::random(6), 'name' => 'F', 'type' => 'hospital',
            'address' => 'Jl', 'city' => 'C', 'province' => 'P', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'public_id' => (string) Str::uuid(),
            'batch_number' => 'RAW-'.Str::random(8),
            'facility_id' => $facilityId,
            'component' => 'whole_blood',
            'volume_ml' => 450,
            'status' => 'quarantined',
            'collected_at' => '2026-01-01 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function test_the_database_rejects_an_expiry_without_a_storage_profile(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_expiry_provenance');

        DB::table('blood_batches')->insert($this->rawRow() + [
            'expires_at' => '2026-02-05 08:00:00',
            'storage_profile_id' => null,
        ]);
    }

    public function test_the_database_rejects_an_expiry_not_after_collection(): void
    {
        $profileId = ComponentStorageProfile::query()->value('id');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_expiry_provenance');

        DB::table('blood_batches')->insert($this->rawRow() + [
            'expires_at' => '2026-01-01 08:00:00',
            'storage_profile_id' => $profileId,
        ]);
    }
}
