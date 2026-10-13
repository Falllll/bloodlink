<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\Facility;
use App\Models\StorageLocation;
use App\Models\StorageTemperatureReading;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTemperatureReading;
use App\Modules\Inventory\Domain\Events\StorageTemperatureOutOfRange;
use App\Modules\Inventory\Domain\ReadingSource;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: guard Sanctum memegang user dari request
 * pertama meski tokennya berganti. Lokasi milik fasilitas lain ditulis langsung.
 */
final class StorageTemperatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withKey(array $headers): array
    {
        return $headers + ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function location(int $facilityId, string $min = '2.0', string $max = '6.0', string $code = 'FRIDGE-01'): StorageLocation
    {
        $location = new StorageLocation([
            'code' => $code,
            'label' => 'Lemari PRC 1',
            'kind' => 'refrigerator',
            'purpose' => 'release_ready',
            'temp_min_celsius' => $min,
            'temp_max_celsius' => $max,
        ]);
        $location->forceFill(['public_id' => (string) Str::uuid()])->assignFacility($facilityId)->save();

        return $location;
    }

    private function record(StorageLocation $location, string $celsius): StorageTemperatureReading
    {
        return app(RecordTemperatureReading::class)->handle($location, $celsius, ReadingSource::MANUAL);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postReading(StorageLocation $location, array $headers, float|string $celsius): TestResponse
    {
        return $this->postJson(
            "/api/v1/storage-locations/{$location->public_id}/temperature-readings",
            ['reading_celsius' => $celsius, 'source' => 'manual'],
            $this->withKey($headers),
        );
    }

    public function test_staff_creates_a_location_owned_by_their_facility(): void
    {
        $facility = Facility::factory()->create();
        $headers = $this->headers($this->userOf($facility->id));

        $response = $this->postJson('/api/v1/storage-locations', [
            'code' => 'FRZ-01',
            'label' => 'Freezer plasma',
            'kind' => 'freezer',
            'purpose' => 'untested',
            'temp_min_celsius' => -40,
            'temp_max_celsius' => -25,
        ], $this->withKey($headers))
            ->assertCreated()
            ->assertJsonPath('data.code', 'FRZ-01')
            ->assertJsonPath('data.kind', 'freezer')
            ->assertJsonPath('data.purpose', 'untested')
            ->assertJsonPath('data.is_active', true);

        // Angka, bukan string "-40.0".
        $this->assertSame(-40.0, $response->json('data.temp_min_celsius'));
        $this->assertSame(-25.0, $response->json('data.temp_max_celsius'));
        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $this->assertDatabaseHas('storage_locations', ['code' => 'FRZ-01', 'facility_id' => $facility->id]);
    }

    public function test_a_location_body_cannot_choose_its_facility_or_an_inverted_band(): void
    {
        $facility = Facility::factory()->create();
        $headers = $this->headers($this->userOf($facility->id));

        $this->postJson('/api/v1/storage-locations', [
            'code' => 'X', 'label' => 'X', 'kind' => 'refrigerator', 'purpose' => 'general',
            'temp_min_celsius' => 6, 'temp_max_celsius' => 2, 'facility_id' => 99,
        ], $this->withKey($headers))
            ->assertStatus(422)
            ->assertJsonPath('error.details.temp_max_celsius.0.rule', 'gte')
            ->assertJsonPath('error.details.facility_id.0.rule', 'not_allowed');

        $this->assertSame(0, StorageLocation::query()->count());
    }

    public function test_a_duplicate_code_in_the_same_facility_is_422_not_500(): void
    {
        $facility = Facility::factory()->create();
        $this->location($facility->id, code: 'FRIDGE-01');

        $this->postJson('/api/v1/storage-locations', [
            'code' => 'FRIDGE-01', 'label' => 'Lagi', 'kind' => 'refrigerator', 'purpose' => 'general',
            'temp_min_celsius' => 2, 'temp_max_celsius' => 6,
        ], $this->withKey($this->headers($this->userOf($facility->id))))
            ->assertStatus(422)
            ->assertJsonPath('error.details.code.0.rule', 'unique');
    }

    public function test_a_reading_inside_the_band_is_201_and_emits_nothing(): void
    {
        Event::fake([StorageTemperatureOutOfRange::class]);
        $facility = Facility::factory()->create();
        $location = $this->location($facility->id);

        $response = $this->postReading($location, $this->headers($this->userOf($facility->id)), 4.0)
            ->assertCreated()
            ->assertJsonPath('data.is_out_of_range', false)
            ->assertJsonPath('data.storage_location_id', $location->public_id)
            ->assertJsonPath('data.source', 'manual');

        $this->assertSame(4.0, $response->json('data.reading_celsius'));
        Event::assertNotDispatched(StorageTemperatureOutOfRange::class);
    }

    public function test_a_reading_outside_the_band_is_201_not_422_and_emits_once(): void
    {
        Event::fake([StorageTemperatureOutOfRange::class]);
        $facility = Facility::factory()->create();
        $location = $this->location($facility->id);
        $staff = $this->userOf($facility->id);

        $this->postReading($location, $this->headers($staff), 12.0)
            ->assertCreated()
            ->assertJsonPath('data.is_out_of_range', true);

        Event::assertDispatchedTimes(StorageTemperatureOutOfRange::class, 1);
        Event::assertDispatched(
            StorageTemperatureOutOfRange::class,
            fn (StorageTemperatureOutOfRange $event): bool => $event->storageLocationId === $location->id
                && $event->readingCelsius === '12.0'
                && $event->occurredAt === StorageTemperatureReading::query()->sole()->recorded_at->toAtomString(),
        );
        $this->assertSame($staff->id, StorageTemperatureReading::query()->sole()->recorded_by);
    }

    /** Batas inklusif: keempat sisi tepi diuji, bukan hanya dua. */
    public function test_the_band_is_inclusive_at_both_edges(): void
    {
        $location = $this->location(Facility::factory()->create()->id, '2.0', '6.0');

        $this->assertFalse($this->record($location, '2.0')->is_out_of_range);
        $this->assertFalse($this->record($location, '6.0')->is_out_of_range);
        $this->assertTrue($this->record($location, '1.9')->is_out_of_range);
        $this->assertTrue($this->record($location, '6.1')->is_out_of_range);
    }

    /** Perbandingan string akan menyimpulkan '-25.0' di luar '-30.0'..'-20.0'. */
    public function test_a_negative_band_is_compared_as_numbers_not_strings(): void
    {
        $location = $this->location(Facility::factory()->create()->id, '-30.0', '-20.0');

        $this->assertFalse($this->record($location, '-25.0')->is_out_of_range);
        $this->assertTrue($this->record($location, '-19.9')->is_out_of_range);
        $this->assertTrue($this->record($location, '-30.1')->is_out_of_range);
    }

    /** Yang dinilai adalah nilai yang tersimpan (numeric(4,1)), bukan presisi input. */
    public function test_extra_precision_is_judged_as_the_stored_value(): void
    {
        $location = $this->location(Facility::factory()->create()->id, '2.0', '6.0');

        $reading = $this->record($location, '6.04')->refresh();

        $this->assertSame('6.0', $reading->reading_celsius);
        $this->assertFalse($reading->is_out_of_range);
    }

    public function test_widening_the_band_later_does_not_rewrite_an_old_verdict(): void
    {
        $location = $this->location(Facility::factory()->create()->id, '2.0', '6.0');
        $reading = $this->record($location, '8.0');
        $this->assertTrue($reading->is_out_of_range);

        $location->forceFill(['temp_max_celsius' => '10.0'])->save();

        $this->assertTrue($reading->fresh()?->is_out_of_range);
        // Pembacaan baru dinilai terhadap band baru.
        $this->assertFalse($this->record($location, '8.0')->is_out_of_range);
    }

    public function test_staff_of_another_facility_does_not_see_the_location(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $theirs = $this->location($facilityA->id, code: 'A-01');
        $ours = $this->location($facilityB->id, code: 'B-01');

        $response = $this->getJson('/api/v1/storage-locations', $this->headers($this->userOf($facilityB->id)))->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($ours->public_id, $ids);
        $this->assertNotContains($theirs->public_id, $ids);
    }

    public function test_staff_of_another_facility_cannot_record_a_reading(): void
    {
        $facilityA = Facility::factory()->create();
        $theirs = $this->location($facilityA->id);
        $headers = $this->headers($this->userOf(Facility::factory()->create()->id));

        $this->postReading($theirs, $headers, 4.0)->assertForbidden();
        $this->getJson("/api/v1/storage-locations/{$theirs->public_id}/temperature-readings", $headers)->assertForbidden();

        $this->assertSame(0, StorageTemperatureReading::query()->count());
    }

    public function test_a_donor_account_cannot_reach_storage(): void
    {
        $facility = Facility::factory()->create();
        $location = $this->location($facility->id);
        $headers = $this->headers($this->userOf($facility->id, 'donor'));

        $this->getJson('/api/v1/storage-locations', $headers)->assertForbidden();
        $this->postReading($location, $headers, 4.0)->assertForbidden();

        $this->assertSame(0, StorageTemperatureReading::query()->count());
    }

    public function test_the_readings_list_can_be_narrowed_to_out_of_range(): void
    {
        $facility = Facility::factory()->create();
        $location = $this->location($facility->id);
        $this->record($location, '4.0');
        $this->record($location, '9.0');
        $this->record($location, '1.0');

        $this->getJson(
            "/api/v1/storage-locations/{$location->public_id}/temperature-readings?filter[is_out_of_range]=1&sort=-recorded_at",
            $this->headers($this->userOf($facility->id)),
        )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_out_of_range', true)
            ->assertJsonPath('data.1.is_out_of_range', true);
    }

    public function test_an_unknown_body_key_on_a_reading_is_422(): void
    {
        $facility = Facility::factory()->create();
        $location = $this->location($facility->id);

        $this->postJson(
            "/api/v1/storage-locations/{$location->public_id}/temperature-readings",
            ['reading_celsius' => 4.0, 'source' => 'manual', 'is_out_of_range' => false],
            $this->withKey($this->headers($this->userOf($facility->id))),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.details.is_out_of_range.0.rule', 'not_allowed');
    }

    public function test_the_database_rejects_an_inverted_band(): void
    {
        $location = $this->location(Facility::factory()->create()->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('storage_locations_band_shape');

        DB::table('storage_locations')->where('id', $location->id)->update(['temp_min_celsius' => 99]);
    }
}
