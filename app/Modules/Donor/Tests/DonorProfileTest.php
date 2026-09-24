<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DonorProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    public function test_weight_can_be_recorded_and_read_back(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}/health-status",
            ['weight_kg' => 65.5],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertOk()
            ->assertJsonPath('data.weight_kg', '65.50');

        $show = $this->getJson("/api/v1/donors/{$donor->public_id}", $this->bearer($staff));

        $show->assertOk()
            ->assertJsonPath('data.weight_kg', '65.50');
    }

    public function test_a_below_minimum_weight_is_accepted_not_rejected(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}/health-status",
            ['weight_kg' => 42],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertOk();
    }

    public function test_changing_the_phone_to_an_existing_number_returns_409(): void
    {
        $facility = Facility::factory()->create();
        Donor::factory()->create(['registered_facility_id' => $facility->id, 'phone' => '081200000001']);
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'phone' => '081200000002']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}",
            ['phone' => '081200000001'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_DUPLICATE_PHONE');
    }

    public function test_nik_can_be_set_once_when_null(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => null]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}",
            ['nik' => '3171010190000099'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertOk()
            ->assertJsonPath('data.has_nik', true);

        $this->assertSame(Donor::nikHash('3171010190000099'), $donor->fresh()->nik_hash);
    }

    public function test_changing_an_existing_nik_returns_422(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190000001']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}",
            ['nik' => '3171010190000002'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.details.nik.0.rule', 'nik_not_changeable');
    }

    public function test_writing_to_a_merged_donor_returns_409(): void
    {
        $facility = Facility::factory()->create();
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source->forceFill(['merged_into_id' => $target->id, 'merged_at' => now()])->save();
        $originalCity = $source->city;

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$source->public_id}",
            ['city' => 'Kota Baru'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_MERGE_CONFLICT');

        $this->assertSame($originalCity, $source->fresh()->city);
    }

    public function test_staff_of_another_facility_cannot_update(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facilityA->id]);

        $staffB = User::factory()->create(['facility_id' => $facilityB->id]);
        $this->assignRole($staffB, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}",
            ['city' => 'Kota Lain'],
            $this->withIdempotencyKey($this->bearer($staffB))
        );

        $response->assertStatus(403);
    }

    public function test_the_response_never_contains_the_internal_id_or_nik(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190000003']);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->getJson("/api/v1/donors/{$donor->public_id}", $this->bearer($staff));

        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame($donor->public_id, $data['id']);
        $this->assertArrayNotHasKey('nik', $data);
        $this->assertArrayNotHasKey('nik_hash', $data);
        $this->assertArrayNotHasKey('phone_hash', $data);
        $this->assertTrue($data['has_nik']);
    }

    public function test_a_weight_change_appears_in_the_audit_log(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id, 'weight_kg' => 60]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->patchJson(
            "/api/v1/donors/{$donor->public_id}/health-status",
            ['weight_kg' => 70],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertOk();

        $log = AuditLog::where('auditable_type', Donor::class)
            ->where('auditable_id', $donor->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('weight_kg', $log->changes['after']);
        $this->assertArrayNotHasKey('phone', $log->changes['after']);
        $this->assertArrayNotHasKey('address', $log->changes['after']);
        $this->assertArrayNotHasKey('nik', $log->changes['after']);
    }
}
