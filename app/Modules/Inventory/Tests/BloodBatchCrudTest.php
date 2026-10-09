<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BloodBatchCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function userWithRole(?int $facilityId, string $role): User
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
        return [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function ffpPayload(float $temperatureC = -22.0): array
    {
        return [
            'component' => 'fresh_frozen_plasma',
            'blood_group' => 'A',
            'rh_factor' => 'positive',
            'volume_ml' => 250,
            'collected_at' => '2026-03-15T08:00:00+00:00',
            'storage_temperature_c' => $temperatureC,
        ];
    }

    public function test_staff_registers_a_quarantined_unit_with_a_profile_derived_expiry(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id, 'hospital_staff');

        $response = $this->postJson('/api/v1/blood-batches', $this->ffpPayload(-35.0), $this->headers($staff));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'quarantined')
            ->assertJsonPath('data.facility_id', $facility->public_id)
            ->assertJsonPath('data.expires_at', '2027-03-15T08:00:00+00:00')
            ->assertJsonPath('data.storage_profile.shelf_life_value', 12)
            ->assertJsonPath('data.storage_profile.shelf_life_unit', 'months');

        $batch = BloodBatch::query()->where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertNotNull($batch->storage_profile_id);
        $this->assertSame(BatchStatus::QUARANTINED, $batch->status);
    }

    public function test_a_client_supplied_expiry_is_rejected_not_silently_dropped(): void
    {
        $staff = $this->userWithRole(Facility::factory()->create()->id, 'hospital_staff');

        $response = $this->postJson(
            '/api/v1/blood-batches',
            $this->ffpPayload() + ['expires_at' => '2030-01-01T00:00:00+00:00', 'status' => 'released'],
            $this->headers($staff),
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.details.expires_at.0.rule', 'not_allowed')
            ->assertJsonPath('error.details.status.0.rule', 'not_allowed');
        $this->assertSame(0, BloodBatch::query()->count());
    }

    public function test_a_temperature_no_profile_covers_is_422_and_writes_nothing(): void
    {
        $staff = $this->userWithRole(Facility::factory()->create()->id, 'hospital_staff');

        $response = $this->postJson('/api/v1/blood-batches', $this->ffpPayload(-10.0), $this->headers($staff));

        $response->assertStatus(422)->assertJsonPath('error.code', 'BATCH_EXPIRY_UNDETERMINABLE');
        $this->assertSame(0, BloodBatch::query()->count());
    }

    public function test_a_donor_may_not_register_a_unit(): void
    {
        $donor = $this->userWithRole(Facility::factory()->create()->id, 'donor');

        $this->postJson('/api/v1/blood-batches', $this->ffpPayload(), $this->headers($donor))->assertForbidden();
        $this->assertSame(0, BloodBatch::query()->count());
    }

    public function test_a_global_admin_may_not_register_a_unit(): void
    {
        // Operator tanpa fasilitas tidak punya tempat menaruh unit.
        $globalAdmin = $this->userWithRole(null, 'admin');

        $this->postJson('/api/v1/blood-batches', $this->ffpPayload(), $this->headers($globalAdmin))->assertForbidden();
        $this->assertSame(0, BloodBatch::query()->count());
    }

    public function test_a_batch_of_another_facility_cannot_be_read_or_moved(): void
    {
        $facilityA = Facility::factory()->create();
        $staffA = $this->userWithRole($facilityA->id, 'hospital_staff');
        $own = BloodBatch::factory()->create(['facility_id' => $facilityA->id]);
        $other = BloodBatch::factory()->create();

        $this->getJson("/api/v1/blood-batches/{$own->public_id}", $this->headers($staffA))
            ->assertOk()
            ->assertJsonPath('data.id', $own->public_id);
        $this->getJson("/api/v1/blood-batches/{$other->public_id}", $this->headers($staffA))->assertForbidden();
        $this->patchJson("/api/v1/blood-batches/{$other->public_id}/status", ['status' => 'testing'], $this->headers($staffA))
            ->assertForbidden();
    }

    public function test_a_status_update_can_never_release_a_unit(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id, 'hospital_staff');
        $batch = BloodBatch::factory()->create(['facility_id' => $facility->id, 'status' => 'testing']);

        $response = $this->patchJson("/api/v1/blood-batches/{$batch->public_id}/status", ['status' => 'released'], $this->headers($staff));

        $response->assertStatus(409)->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');
        $this->assertSame(BatchStatus::TESTING, $batch->fresh()?->status);
    }

    public function test_legal_transitions_succeed_and_terminal_states_stay_terminal(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id, 'hospital_staff');
        $batch = BloodBatch::factory()->create(['facility_id' => $facility->id, 'status' => 'quarantined']);
        $url = "/api/v1/blood-batches/{$batch->public_id}/status";

        $this->patchJson($url, ['status' => 'testing'], $this->headers($staff))
            ->assertOk()->assertJsonPath('data.status', 'testing');

        $this->patchJson($url, ['status' => 'discarded'], $this->headers($staff))
            ->assertStatus(422)->assertJsonPath('error.details.discard_reason.0.rule', 'required_if');

        $this->patchJson($url, ['status' => 'discarded', 'discard_reason' => 'Kantong bocor saat pemisahan.'], $this->headers($staff))
            ->assertOk()->assertJsonPath('data.discard_reason', 'Kantong bocor saat pemisahan.');

        $this->patchJson($url, ['status' => 'testing'], $this->headers($staff))
            ->assertStatus(409)->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');
        $this->assertSame(BatchStatus::DISCARDED, $batch->fresh()?->status);
    }

    public function test_the_list_needs_a_token_and_is_scoped_to_the_facility(): void
    {
        $this->getJson('/api/v1/blood-batches')->assertUnauthorized();

        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id, 'hospital_staff');
        BloodBatch::factory()->count(2)->create(['facility_id' => $facility->id]);
        BloodBatch::factory()->create();

        $response = $this->getJson('/api/v1/blood-batches?sort=expires_at', $this->headers($staff));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    // Satu user per test: dalam satu test, guard Sanctum tetap memegang user dari
    // request pertama meski token berganti, jadi request kedua berjalan sebagai
    // user pertama. Pasangan positif karenanya hidup di test sendiri.

    public function test_a_donor_bound_to_a_facility_cannot_list_its_inventory(): void
    {
        $facility = Facility::factory()->create();
        BloodBatch::factory()->create(['facility_id' => $facility->id]);
        $donor = $this->userWithRole($facility->id, 'donor');

        // Unitnya ada dan terlihat oleh scope fasilitas: 403 di bawah berasal dari
        // izin, bukan dari data yang kosong.
        $this->assertSame(1, BloodBatch::query()->visibleTo($donor)->count());

        $this->getJson('/api/v1/blood-batches', $this->headers($donor))->assertForbidden();
    }

    public function test_hospital_staff_of_the_same_facility_still_lists_its_inventory(): void
    {
        $facility = Facility::factory()->create();
        BloodBatch::factory()->create(['facility_id' => $facility->id]);

        $this->getJson('/api/v1/blood-batches', $this->headers($this->userWithRole($facility->id, 'hospital_staff')))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_donor_bound_to_a_facility_cannot_read_a_single_unit(): void
    {
        $facility = Facility::factory()->create();
        $batch = BloodBatch::factory()->create(['facility_id' => $facility->id]);

        $this->getJson("/api/v1/blood-batches/{$batch->public_id}", $this->headers($this->userWithRole($facility->id, 'donor')))
            ->assertForbidden();
    }

    public function test_hospital_staff_of_the_same_facility_still_reads_a_single_unit(): void
    {
        $facility = Facility::factory()->create();
        $batch = BloodBatch::factory()->create(['facility_id' => $facility->id]);

        $this->getJson("/api/v1/blood-batches/{$batch->public_id}", $this->headers($this->userWithRole($facility->id, 'hospital_staff')))
            ->assertOk()
            ->assertJsonPath('data.id', $batch->public_id);
    }

    public function test_a_global_admin_still_reads_every_facility(): void
    {
        BloodBatch::factory()->create(['facility_id' => Facility::factory()->create()->id]);
        BloodBatch::factory()->create(['facility_id' => Facility::factory()->create()->id]);

        $this->getJson('/api/v1/blood-batches', $this->headers($this->userWithRole(null, 'admin')))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_second_page_is_reachable_with_the_cursor_from_the_first_page(): void
    {
        $facility = Facility::factory()->create();
        BloodBatch::factory()->count(3)->create(['facility_id' => $facility->id]);
        $headers = $this->headers($this->userWithRole($facility->id, 'hospital_staff'));

        $first = $this->getJson('/api/v1/blood-batches?per_page=2', $headers)->assertOk();
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/api/v1/blood-batches?per_page=2&cursor='.$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
    }
}
