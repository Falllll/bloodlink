<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\AuditLog;
use App\Models\Deferral;
use App\Models\Donor;
use App\Models\DonorScreening;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DonorScreeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DeferralReasonSeeder::class);
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function assignRole(User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withIdempotencyKey(array $headers): array
    {
        return array_merge($headers, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    private function passingPayload(): array
    {
        return [
            'haemoglobin_g_dl' => 14.0,
            'systolic_mmhg' => 120,
            'diastolic_mmhg' => 80,
            'pulse_bpm' => 70,
            'temperature_c' => 36.5,
            'weight_kg' => 70,
        ];
    }

    public function test_a_passing_screening_creates_no_deferral(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            $this->passingPayload(),
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.findings', []);

        $this->assertSame(0, Deferral::query()->where('donor_id', $donor->id)->count());
    }

    public function test_a_failing_screening_creates_exactly_one_deferral(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 9.0],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.passed', false);

        $this->assertSame(1, Deferral::query()->where('donor_id', $donor->id)->count());
    }

    public function test_the_automatic_deferral_is_temporary_without_a_duration(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 9.0],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $deferral = Deferral::query()->where('donor_id', $donor->id)->firstOrFail();

        $this->assertSame('temporary', $deferral->type->value);
        $this->assertSame('screening', $deferral->source->value);
        $this->assertNull($deferral->duration_value);
        $this->assertNull($deferral->duration_unit);
    }

    public function test_the_response_returns_decimal_haemoglobin_and_temperature(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 14.0, 'temperature_c' => 37.6],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.haemoglobin_g_dl', 14.0)
            ->assertJsonPath('data.temperature_c', 37.6);
    }

    public function test_a_male_donor_with_haemoglobin_12_5_fails_the_screening(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 12.5],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.findings.0', 'HAEMOGLOBIN_BELOW_MINIMUM');
    }

    public function test_a_female_donor_with_haemoglobin_12_5_passes_the_screening(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'female']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 12.5],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.passed', true);
    }

    public function test_temperature_exactly_37_6_fails_and_37_5_passes(): void
    {
        $facility = Facility::factory()->create();
        $donorHigh = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);
        $donorOk = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $responseHigh = $this->postJson(
            "/api/v1/donors/{$donorHigh->public_id}/screenings",
            [...$this->passingPayload(), 'temperature_c' => 37.6],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $responseOk = $this->postJson(
            "/api/v1/donors/{$donorOk->public_id}/screenings",
            [...$this->passingPayload(), 'temperature_c' => 37.5],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $responseHigh->assertStatus(201)->assertJsonPath('data.passed', false);
        $responseOk->assertStatus(201)->assertJsonPath('data.passed', true);
    }

    public function test_a_failing_screening_still_returns_201_and_is_persisted(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'male']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 9.0],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201);

        $this->assertSame(1, DonorScreening::query()->where('donor_id', $donor->id)->count());
    }

    public function test_the_database_rejects_a_zero_pulse(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_screenings_positive_measurements');

        DB::table('donor_screenings')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'haemoglobin_dg_dl' => 140,
            'systolic_mmhg' => 120,
            'diastolic_mmhg' => 80,
            'pulse_bpm' => 0,
            'temperature_dc' => 365,
            'weight_kg' => 70,
            'planned_volume_ml' => 450,
            'passed' => true,
            'findings' => json_encode([]),
            'screened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_haemoglobin_12_0_passes_for_a_female_and_returns_exactly_12_0(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'sex' => 'female']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            [...$this->passingPayload(), 'haemoglobin_g_dl' => 12.0],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.haemoglobin_g_dl', 12.0);
    }

    public function test_staff_of_another_facility_cannot_record_screening(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facilityA->id]);

        $staffB = User::factory()->create(['facility_id' => $facilityB->id]);
        $this->assignRole($staffB, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            $this->passingPayload(),
            $this->withIdempotencyKey($this->bearer($staffB))
        );

        $response->assertStatus(403);
    }

    public function test_the_endpoint_needs_a_token(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            $this->passingPayload(),
            $this->withIdempotencyKey([])
        );

        $response->assertStatus(401);
    }

    public function test_the_endpoint_needs_an_idempotency_key(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            $this->passingPayload(),
            $this->bearer($staff)
        );

        $response->assertStatus(400);
    }

    public function test_a_screening_appears_in_the_audit_log(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/screenings",
            $this->passingPayload(),
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201);

        $screening = DonorScreening::query()->where('donor_id', $donor->id)->firstOrFail();

        $log = AuditLog::where('auditable_type', DonorScreening::class)
            ->where('auditable_id', $screening->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
    }

    public function test_recording_a_screening_for_a_merged_donor_returns_409(): void
    {
        $facility = Facility::factory()->create();
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source->forceFill(['merged_into_id' => $target->id, 'merged_at' => now()])->save();

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$source->public_id}/screenings",
            $this->passingPayload(),
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_MERGE_CONFLICT');
    }
}
