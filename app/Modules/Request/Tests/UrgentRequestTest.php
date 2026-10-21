<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\StockTransfer;
use App\Models\User;
use App\Modules\Request\Infrastructure\NearbyStockQuery;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Permintaan darurat + pindaian stok terdekat (kartu 380). Satu user terautentikasi
 * per test. Titik asal di Monas; 1 derajat lintang ~ 111 km, jadi +0.09 ~ 10 km
 * dan +0.45 ~ 50 km (radius default 30 km).
 */
final class UrgentRequestTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    private const float LAT = -6.1754;

    private const float LNG = 106.8272;

    private Facility $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->requester = $this->facilityAt(0.0);
    }

    private function facilityAt(?float $northDegrees): Facility
    {
        $facility = Facility::factory()->create();

        if ($northDegrees !== null) {
            DB::table('facilities')->where('id', $facility->id)->update([
                'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $northDegrees)),
            ]);
        }

        return $facility;
    }

    private function unit(Facility $facility, string $group = 'O', string $rh = 'negative', string $status = 'released', string $expiresIn = '+10 days', string $component = 'packed_red_cells'): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => $component, 'facility_id' => $facility->id]);
        $unit->forceFill([
            'blood_group' => $group,
            'rh_factor' => $rh,
            'collected_at' => now()->subDays(20),
            'expires_at' => now()->modify($expiresIn),
            'status' => $status,
            'released_at' => in_array($status, ['released', 'reserved'], true) ? now()->subDays(19) : null,
        ])->save();

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function urgent(array $overrides = [], ?User $user = null): TestResponse
    {
        $user ??= $this->staff($this->requester);

        return $this->postJson('/api/v1/blood-requests/urgent', [
            'patient_reference' => 'RM-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'quantity' => 2,
            'urgency' => 'emergency',
            'needed_by' => now()->addHours(2)->toIso8601String(),
            ...$overrides,
        ], [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function staff(Facility $facility, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, int>
     */
    private function unitsByFacility(TestResponse $response): array
    {
        return array_column($response->json('data.nearby_stock'), 'compatible_units', 'facility_id');
    }

    public function test_a_neighbour_within_radius_is_found_and_one_beyond_it_is_not(): void
    {
        $near = $this->facilityAt(0.09);
        $far = $this->facilityAt(0.45);
        foreach (range(1, 3) as $_) {
            $this->unit($near);
        }
        foreach (range(1, 10) as $_) {
            $this->unit($far);
        }

        $response = $this->urgent()->assertCreated()
            ->assertJsonPath('data.request.urgency', 'emergency')
            ->assertJsonPath('data.request.status', 'open')
            ->assertJsonPath('data.nearby_stock_status', 'scanned');

        $this->assertSame([$near->public_id => 3], $this->unitsByFacility($response));
        $distance = $response->json('data.nearby_stock.0.distance_meters');
        $this->assertGreaterThan(9000, $distance);
        $this->assertLessThan(11000, $distance);
    }

    public function test_the_requester_comes_first_at_zero_metres_then_by_distance(): void
    {
        $mid = $this->facilityAt(0.15);
        $near = $this->facilityAt(0.05);
        $this->unit($mid);
        $this->unit($near);
        $this->unit($this->requester);

        $response = $this->urgent()->assertCreated();

        $this->assertSame([$this->requester->public_id, $near->public_id, $mid->public_id], array_column($response->json('data.nearby_stock'), 'facility_id'));
        $response->assertJsonPath('data.nearby_stock.0.distance_meters', 0)
            ->assertJsonPath('data.nearby_stock.0.is_self', true)
            ->assertJsonPath('data.nearby_stock.1.is_self', false);
    }

    public function test_the_scan_returns_aggregates_and_no_unit_identity(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $units = [$this->unit($neighbour), $this->unit($neighbour)];

        $response = $this->urgent()->assertCreated();

        $this->assertSame(
            ['facility_id', 'facility_name', 'distance_meters', 'compatible_units', 'is_self'],
            array_keys($response->json('data.nearby_stock.0')),
        );

        $body = (string) $response->getContent();
        foreach ($units as $unit) {
            $this->assertStringNotContainsString($unit->public_id, $body);
            $this->assertStringNotContainsString($unit->batch_number, $body);
        }
    }

    /** Kompatibilitas, bukan kesamaan golongan: AB+ boleh menerima O. */
    public function test_an_ab_positive_request_finds_group_o_stock(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour, 'O', 'negative');
        $this->unit($neighbour, 'O', 'positive');

        $response = $this->urgent(['blood_group' => 'AB', 'rh_factor' => 'positive'])->assertCreated();

        $this->assertSame([$neighbour->public_id => 2], $this->unitsByFacility($response));
    }

    public function test_an_o_negative_request_ignores_incompatible_groups_and_rh_positive(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour, 'O', 'negative');
        $this->unit($neighbour, 'O', 'positive');
        $this->unit($neighbour, 'A', 'negative');
        $this->unit($neighbour, 'AB', 'negative');

        $response = $this->urgent()->assertCreated();

        $this->assertSame([$neighbour->public_id => 1], $this->unitsByFacility($response));
    }

    /** RhD tidak diperiksa untuk plasma (tidak ada aturan bersumber), ABO arahnya terbalik. */
    public function test_plasma_follows_the_plasma_direction_of_the_gate(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour, 'AB', 'positive', component: 'fresh_frozen_plasma');
        $this->unit($neighbour, 'AB', 'negative', component: 'fresh_frozen_plasma');
        $this->unit($neighbour, 'O', 'negative', component: 'fresh_frozen_plasma');

        $response = $this->urgent(['component' => 'fresh_frozen_plasma', 'blood_group' => 'A', 'rh_factor' => 'negative'])->assertCreated();

        $this->assertSame([$neighbour->public_id => 2], $this->unitsByFacility($response));
    }

    public function test_a_reserved_unit_is_not_counted(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour);
        $this->unit($neighbour, status: 'reserved');

        $this->assertSame([$neighbour->public_id => 1], $this->unitsByFacility($this->urgent()->assertCreated()));
    }

    public function test_an_expired_unit_is_not_counted(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour);
        $this->unit($neighbour, expiresIn: '-1 hour');

        $this->assertSame([$neighbour->public_id => 1], $this->unitsByFacility($this->urgent()->assertCreated()));
    }

    /** Kantong yang mati sebelum jadwal transfusi tidak memenuhi permintaan ini. */
    public function test_a_unit_expiring_before_needed_by_is_not_counted(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour);
        $this->unit($neighbour, expiresIn: '+1 hour');

        $this->assertSame([$neighbour->public_id => 1], $this->unitsByFacility($this->urgent()->assertCreated()));
    }

    public function test_a_unit_with_an_open_transfer_is_not_counted(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour);
        $moving = $this->unit($neighbour);
        $transfer = new StockTransfer;
        $transfer->forceFill([
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $moving->id,
            'from_facility_id' => $neighbour->id,
            'to_facility_id' => $this->facilityAt(0.2)->id,
            'status' => 'requested',
            'requested_by' => User::factory()->create()->id,
        ])->save();

        $this->assertSame([$neighbour->public_id => 1], $this->unitsByFacility($this->urgent()->assertCreated()));
    }

    public function test_units_in_quarantine_or_testing_are_not_counted(): void
    {
        $neighbour = $this->facilityAt(0.09);
        $this->unit($neighbour, status: 'quarantined');
        $this->unit($neighbour, status: 'testing');

        $this->assertSame([], $this->urgent()->assertCreated()->json('data.nearby_stock'));
    }

    public function test_inactive_and_deleted_facilities_are_not_offered(): void
    {
        $inactive = $this->facilityAt(0.05);
        $deleted = $this->facilityAt(0.06);
        $this->unit($inactive);
        $this->unit($deleted);
        $inactive->forceFill(['is_active' => false])->save();
        $deleted->delete();

        $this->assertSame([], $this->urgent()->assertCreated()->json('data.nearby_stock'));
    }

    /** Pindaian adalah bantuan, bukan syarat. */
    public function test_a_requester_without_coordinates_still_gets_its_request(): void
    {
        $this->requester = $this->facilityAt(null);
        $this->unit($this->facilityAt(0.09));

        $response = $this->urgent()->assertCreated()
            ->assertJsonPath('data.nearby_stock', [])
            ->assertJsonPath('data.nearby_stock_status', 'no_requester_location');

        $this->assertTrue(BloodRequest::query()->withoutGlobalScopes()->where('public_id', $response->json('data.request.id'))->exists());
    }

    /** Trombosit tidak punya matriks bersumber: "tidak bisa dipindai", bukan "tidak ada stok". */
    public function test_platelets_report_no_sourced_rule_instead_of_no_stock(): void
    {
        $this->unit($this->facilityAt(0.09), component: 'platelet_concentrate', expiresIn: '+3 days');

        $this->urgent(['component' => 'platelet_concentrate'])->assertCreated()
            ->assertJsonPath('data.nearby_stock', [])
            ->assertJsonPath('data.nearby_stock_status', 'no_sourced_rule');
    }

    public function test_a_routine_request_does_not_use_this_door(): void
    {
        $this->urgent(['urgency' => 'routine'])->assertStatus(422);

        $this->assertSame(0, BloodRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_the_shared_rules_still_apply(): void
    {
        $this->urgent(['quantity' => 0])->assertStatus(422);
    }

    public function test_a_user_without_a_facility_cannot_create_one(): void
    {
        $donor = User::factory()->create(['facility_id' => null]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of(null));
        $donor->assignRole('donor');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $this->urgent(user: $donor)->assertForbidden();
    }

    public function test_the_scan_uses_the_spatial_index(): void
    {
        // Tabel test terlalu kecil: tanpa ini planner memilih seq scan (pola FefoAllocationTest).
        $plan = $this->queryPlan(app(NearbyStockQuery::class)->aggregate(self::LAT, self::LNG, 30000, 'packed_red_cells', [['group' => 'O', 'rh' => 'negative']], now()));

        $this->assertStringContainsString('facilities_location_gist', $plan);
    }
}
