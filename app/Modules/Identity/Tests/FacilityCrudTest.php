<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class FacilityCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, RoleEnum $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role->value);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /**
     * @return array<string, string>
     */
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

    public function test_an_admin_can_create_a_facility(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'RSUD-01',
            'name' => 'RSUD Contoh',
            'type' => 'hospital',
            'address' => 'Jl. Contoh No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'phone' => '02112345678',
            'latitude' => -6.2,
            'longitude' => 106.81,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'RSUD-01');

        $this->assertTrue(Str::isUuid($response->json('data.id')));

        $this->assertDatabaseHas('facilities', ['code' => 'RSUD-01']);
    }

    public function test_hospital_staff_cannot_create_a_facility(): void
    {
        $facility = Facility::factory()->create();
        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, RoleEnum::HOSPITAL_STAFF);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'RSUD-02',
            'name' => 'RSUD Lain',
            'type' => 'hospital',
            'address' => 'Jl. Lain No. 2',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'phone' => '02287654321',
            'latitude' => -6.9,
            'longitude' => 107.6,
        ], $this->withIdempotencyKey($this->bearer($staff)));

        $response->assertStatus(403);

        $this->getJson('/api/v1/facilities', $this->bearer($staff))->assertOk();
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        Facility::factory()->create(['code' => 'DUPE-01']);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'DUPE-01',
            'name' => 'RSUD Duplikat',
            'type' => 'hospital',
            'address' => 'Jl. Duplikat No. 3',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
            'phone' => '03112345678',
            'latitude' => -7.25,
            'longitude' => 112.75,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_lowercase_code_is_stored_uppercase(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'rsud-03',
            'name' => 'RSUD Kecil',
            'type' => 'blood_bank',
            'address' => 'Jl. Kecil No. 4',
            'city' => 'Semarang',
            'province' => 'Jawa Tengah',
            'phone' => '02412345678',
            'latitude' => -6.97,
            'longitude' => 110.42,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'RSUD-03');

        $this->assertDatabaseHas('facilities', ['code' => 'RSUD-03']);
    }

    public function test_deactivate_keeps_the_row(): void
    {
        $facility = Facility::factory()->create(['is_active' => true]);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->patchJson(
            "/api/v1/facilities/{$facility->public_id}/deactivate",
            [],
            $this->withIdempotencyKey($this->bearer($admin))
        );

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('facilities', ['id' => $facility->id, 'is_active' => false]);
    }

    public function test_a_hospital_without_coordinates_is_rejected(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'RSUD-05',
            'name' => 'RSUD Tanpa Koordinat',
            'type' => 'hospital',
            'address' => 'Jl. Tanpa Koordinat No. 5',
            'city' => 'Medan',
            'province' => 'Sumatera Utara',
            'phone' => '06112345678',
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_updating_a_facility_without_changing_its_code_is_accepted(): void
    {
        $facility = Facility::factory()->create(['code' => 'RSUD-10']);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->patchJson(
            "/api/v1/facilities/{$facility->public_id}",
            ['code' => 'RSUD-10'],
            $this->withIdempotencyKey($this->bearer($admin))
        );

        $response->assertOk()
            ->assertJsonPath('data.code', 'RSUD-10');
    }

    public function test_updating_a_facility_to_another_facilitys_code_is_rejected(): void
    {
        Facility::factory()->create(['code' => 'RSUD-11']);
        $facility = Facility::factory()->create(['code' => 'RSUD-12']);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->patchJson(
            "/api/v1/facilities/{$facility->public_id}",
            ['code' => 'RSUD-11'],
            $this->withIdempotencyKey($this->bearer($admin))
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_nearby_uses_the_gist_index(): void
    {
        // Planner sudah memilih index scan secara alami di sini (dibuktikan
        // dengan menghapus baris ini dan test tetap hijau), tapi seq scan
        // tetap dimatikan agar hasil tidak goyah kalau data test membesar.
        DB::statement('SET enable_seqscan = off;');

        $sql = Facility::query()->nearby(-6.2, 106.81, 5000)->toSql();
        $plan = DB::select('EXPLAIN '.$sql, [106.81, -6.2, 5000]);

        $this->assertStringContainsString('facilities_location_gist', json_encode($plan));
    }
}
