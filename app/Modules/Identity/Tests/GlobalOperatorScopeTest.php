<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use App\Shared\Database\FacilityGuard;
use Database\Seeders\DevUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GlobalOperatorScopeTest extends TestCase
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

        // Poison the in-memory team id so a later HTTP call in this same test
        // process only passes an assertion if the request's own middleware
        // (BindFacilityContext) actually re-set it -- not because this setup
        // step happened to leave the right value lying around.
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_facility_bound_admin_is_not_a_global_operator(): void
    {
        $facility = Facility::factory()->create();

        $admin = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($admin->facility_id));

        $this->assertFalse($admin->isGlobalOperator());
    }

    public function test_an_unbound_admin_is_still_a_global_operator(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($admin->facility_id));

        $this->assertTrue($admin->isGlobalOperator());
    }

    public function test_a_facility_bound_admin_only_sees_batches_from_their_own_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $adminA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($adminA, RoleEnum::ADMIN);

        BloodBatch::factory()->count(3)->create(['facility_id' => $facilityA->id]);
        BloodBatch::factory()->count(2)->create(['facility_id' => $facilityB->id]);

        $response = $this->getJson('/api/v1/blood-batches', $this->bearer($adminA));

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));

        foreach ($response->json('data') as $batch) {
            $this->assertSame($facilityA->id, $batch['facility_id']);
        }
    }

    public function test_a_facility_bound_admin_cannot_read_a_single_batch_from_another_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $adminA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($adminA, RoleEnum::ADMIN);

        $batchFromB = BloodBatch::factory()->create(['facility_id' => $facilityB->id]);

        $this->expectException(AuthorizationException::class);

        FacilityGuard::assertVisible($batchFromB, $adminA);
    }

    public function test_hospital_staff_cannot_update_their_own_facility(): void
    {
        $facilityA = Facility::factory()->create();

        $staffA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($staffA, RoleEnum::HOSPITAL_STAFF);

        $response = $this->patchJson(
            "/api/v1/facilities/{$facilityA->public_id}",
            [],
            array_merge($this->bearer($staffA), ['Idempotency-Key' => (string) Str::uuid()])
        );

        $response->assertStatus(403);
    }

    public function test_a_facility_bound_admin_cannot_update_another_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $adminA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($adminA, RoleEnum::ADMIN);

        $responseA = $this->patchJson(
            "/api/v1/facilities/{$facilityA->public_id}",
            [],
            array_merge($this->bearer($adminA), ['Idempotency-Key' => (string) Str::uuid()])
        );

        $responseB = $this->patchJson(
            "/api/v1/facilities/{$facilityB->public_id}",
            [],
            array_merge($this->bearer($adminA), ['Idempotency-Key' => (string) Str::uuid()])
        );

        $responseA->assertOk();
        $responseB->assertStatus(403);
    }

    public function test_the_seeded_facility_admin_cannot_read_other_facilities(): void
    {
        Facility::factory()->state(['type' => 'hospital'])->create();
        $facilityB = Facility::factory()->state(['type' => 'blood_bank'])->create();

        $this->seed(DevUserSeeder::class);

        BloodBatch::factory()->count(2)->create(['facility_id' => $facilityB->id]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-rs@bloodlink.test',
            'password' => (string) config('dev.seed_password'),
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertOk();

        $token = $response->json('data.token');

        $scopedResponse = $this->getJson('/api/v1/blood-batches', ['Authorization' => 'Bearer '.$token]);

        $scopedResponse->assertOk();

        foreach ($scopedResponse->json('data') as $batch) {
            $this->assertNotSame($facilityB->id, $batch['facility_id']);
        }
    }
}
