<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use App\Shared\Database\FacilityGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class FacilityScopeTest extends TestCase
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
        // (BindFacilityContext) actually re-set it — not because this setup
        // step happened to leave the right value lying around.
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

    public function test_staff_only_sees_batches_from_their_own_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $staffA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($staffA, RoleEnum::HOSPITAL_STAFF);

        BloodBatch::factory()->count(3)->create(['facility_id' => $facilityA->id]);
        BloodBatch::factory()->count(2)->create(['facility_id' => $facilityB->id]);

        $response = $this->getJson('/api/v1/blood-batches', $this->bearer($staffA));

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));

        foreach ($response->json('data') as $batch) {
            $this->assertSame($facilityA->id, $batch['facility_id']);
        }
    }

    public function test_an_admin_sees_batches_from_every_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        BloodBatch::factory()->count(3)->create(['facility_id' => $facilityA->id]);
        BloodBatch::factory()->count(2)->create(['facility_id' => $facilityB->id]);

        $response = $this->getJson('/api/v1/blood-batches', $this->bearer($admin));

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
    }

    public function test_a_donor_with_no_facility_sees_nothing(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $donor = User::factory()->create(['facility_id' => null]);
        $this->assignRole($donor, RoleEnum::DONOR);

        BloodBatch::factory()->count(3)->create(['facility_id' => $facilityA->id]);
        BloodBatch::factory()->count(2)->create(['facility_id' => $facilityB->id]);

        $response = $this->getJson('/api/v1/blood-batches', $this->bearer($donor));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_staff_cannot_read_a_single_batch_from_another_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $staffA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($staffA, RoleEnum::HOSPITAL_STAFF);

        $batchFromB = BloodBatch::factory()->create(['facility_id' => $facilityB->id]);

        $this->expectException(AuthorizationException::class);

        FacilityGuard::assertVisible($batchFromB, $staffA);
    }

    public function test_the_permission_team_id_is_bound_to_the_authenticated_users_facility(): void
    {
        $facility = Facility::factory()->create();

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, RoleEnum::HOSPITAL_STAFF);

        $response = $this->getJson('/api/v1/_test/scope', $this->bearer($staff));

        $response->assertOk()
            ->assertJsonPath('data.permission_team_id', $facility->id);
    }

    public function test_a_global_user_binds_the_sentinel_scope_not_null(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/_test/scope', $this->bearer($admin));

        $response->assertOk()
            ->assertJsonPath('data.permission_team_id', FacilityScope::GLOBAL_SCOPE);
    }
}
