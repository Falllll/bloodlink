<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rasio crossmatch-ke-transfusi (kartu 440). Satu user terautentikasi per test.
 */
final class CrossmatchTransfusionReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModRequest tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'admin'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return TestResponse<JsonResponse> */
    private function report(User $user, string $query): TestResponse
    {
        return $this->getJson("/api/v1/reports/crossmatch-transfusion?{$query}", [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
        ]);
    }

    /** Alokasi untuk permintaan milik $requester atas kantong milik $owner. */
    private function allocation(Facility $requester, Facility $owner): int
    {
        $actor = User::factory()->create(['facility_id' => $requester->id]);
        // Status unit tidak dibaca laporan ini; quarantined menghindari CHECK bentuk rilis.
        $unit = BloodBatch::factory()->create([
            'facility_id' => $owner->id,
            'status' => 'quarantined',
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'positive',
        ]);

        $requestId = DB::table('blood_requests')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'facility_id' => $requester->id,
            'patient_reference' => 'RM-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'positive',
            'quantity' => 1,
            'urgency' => 'routine',
            'status' => 'fulfilled',
            'needed_by' => '2025-07-01 00:00:00',
            'requested_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('blood_request_allocations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'blood_request_id' => $requestId,
            'blood_batch_id' => $unit->id,
            'allocated_by' => $actor->id,
            'allocated_at' => '2025-07-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crossmatch(int $allocationId, string $result, string $at): void
    {
        DB::table('crossmatches')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_allocation_id' => $allocationId,
            'performed_by' => User::factory()->create()->id,
            'result' => $result,
            'note' => $result === 'compatible' ? null : 'catatan lab',
            'performed_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transfusion(int $allocationId, string $at): void
    {
        DB::table('transfusions')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_allocation_id' => $allocationId,
            'recorded_by' => User::factory()->create()->id,
            'started_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_crossmatch_counts_whatever_its_result(): void
    {
        $facility = Facility::factory()->create();
        $allocation = $this->allocation($facility, $facility);
        $this->crossmatch($allocation, 'inconclusive', '2025-07-02 08:00:00');
        $this->crossmatch($allocation, 'incompatible', '2025-07-02 09:00:00');
        $this->crossmatch($allocation, 'compatible', '2025-07-02 10:00:00');
        $this->transfusion($allocation, '2025-07-03 08:00:00');

        $response = $this->report($this->userOf($facility->id), 'from=2025-07-01&to=2025-07-31&granularity=month');

        $response->assertOk();
        $response->assertJsonPath('data.0.numerator', 3);
        $response->assertJsonPath('data.0.denominator', 1);
        $this->assertSame(3.0, $response->json('data.0.ratio'));
    }

    public function test_a_period_without_transfusions_has_a_null_ratio_not_zero(): void
    {
        $facility = Facility::factory()->create();
        $allocation = $this->allocation($facility, $facility);
        $this->crossmatch($allocation, 'compatible', '2025-07-02 08:00:00');
        $this->transfusion($allocation, '2025-08-01 08:00:00');

        $response = $this->report($this->userOf($facility->id), 'from=2025-07-01&to=2025-09-30&granularity=month');

        $response->assertOk();
        $this->assertSame(
            [
                ['period_start' => '2025-07-01', 'numerator' => 1, 'denominator' => 0, 'ratio' => null],
                ['period_start' => '2025-08-01', 'numerator' => 0, 'denominator' => 1, 'ratio' => 0.0],
                ['period_start' => '2025-09-01', 'numerator' => 0, 'denominator' => 0, 'ratio' => null],
            ],
            $response->json('data'),
        );
    }

    public function test_the_requesting_facility_counts_a_transferred_unit(): void
    {
        $requester = Facility::factory()->create();
        $owner = Facility::factory()->create();
        $allocation = $this->allocation($requester, $owner);
        $this->crossmatch($allocation, 'compatible', '2025-07-02 08:00:00');
        $this->transfusion($allocation, '2025-07-03 08:00:00');

        $response = $this->report($this->userOf($requester->id), 'from=2025-07-01&to=2025-07-31&granularity=month');

        $response->assertOk();
        $response->assertJsonPath('data.0.numerator', 1);
        $response->assertJsonPath('data.0.denominator', 1);
    }

    public function test_the_unit_owner_does_not_count_another_facilitys_request(): void
    {
        $requester = Facility::factory()->create();
        $owner = Facility::factory()->create();
        $allocation = $this->allocation($requester, $owner);
        $this->crossmatch($allocation, 'compatible', '2025-07-02 08:00:00');
        $this->transfusion($allocation, '2025-07-03 08:00:00');

        $response = $this->report($this->userOf($owner->id), 'from=2025-07-01&to=2025-07-31&granularity=month');

        $response->assertOk();
        $response->assertJsonPath('data.0.numerator', 0);
        $response->assertJsonPath('data.0.denominator', 0);
    }

    public function test_hospital_staff_without_report_view_gets_403(): void
    {
        $facility = Facility::factory()->create();

        $this->report($this->userOf($facility->id, 'hospital_staff'), 'from=2025-07-01&to=2025-07-31&granularity=month')
            ->assertForbidden();
    }
}
