<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AppointmentFlowTest extends TestCase
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

    public function test_a_donor_can_book_a_slot(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'booked');
    }

    public function test_a_walk_in_is_born_arrived(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'arrived');

        $this->assertNotNull($response->json('data.arrived_at'));
    }

    public function test_the_full_happy_path_booked_to_completed(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $booked = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $publicId = $booked->json('data.id');

        $screened = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'screened'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $screened->assertOk()->assertJsonPath('data.status', 'screened');
        $this->assertNotNull($screened->json('data.screened_at'));

        $completed = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'completed'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $completed->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($completed->json('data.arrived_at'));
        $this->assertNotNull($completed->json('data.screened_at'));
        $this->assertNotNull($completed->json('data.completed_at'));
    }

    public function test_index_is_scoped_to_the_token_facility_and_paginated(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $donorA = Donor::factory()->create(['registered_facility_id' => $facilityA->id]);
        $donorB = Donor::factory()->create(['registered_facility_id' => $facilityB->id]);

        $staffA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($staffA, 'hospital_staff');

        $this->postJson(
            "/api/v1/donors/{$donorA->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staffA))
        )->assertStatus(201);

        $this->postJson(
            "/api/v1/donors/{$donorB->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer(User::factory()->create(['facility_id' => $facilityB->id])))
        );

        $response = $this->getJson('/api/v1/appointments', $this->bearer($staffA));

        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertSame($donorA->public_id, $row['donor_id']);
        }

        $this->assertArrayHasKey('next_cursor', $response->json('meta'));
    }

    public function test_booked_to_completed_directly_is_rejected(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $booked = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $publicId = $booked->json('data.id');

        $response = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'completed'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');

        $appointment = Appointment::query()->where('public_id', $publicId)->firstOrFail();
        $this->assertSame('booked', $appointment->status->value);
        $this->assertNull($appointment->completed_at);
    }

    public function test_completed_to_arrived_is_rejected(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $appointment = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $publicId = $appointment->json('data.id');

        $this->patchJson("/api/v1/appointments/{$publicId}/status", ['status' => 'screened'], $this->withIdempotencyKey($this->bearer($staff)))->assertOk();
        $this->patchJson("/api/v1/appointments/{$publicId}/status", ['status' => 'completed'], $this->withIdempotencyKey($this->bearer($staff)))->assertOk();

        $response = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'arrived'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');
    }

    public function test_no_show_to_arrived_is_rejected(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $appointment = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        // scheduled_for divalidasi after:now saat booking, jadi baris tidak
        // bisa dibuat langsung dengan jadwal di masa lalu -- backdate lewat
        // query builder untuk mensimulasikan janji yang jamnya sudah lewat.
        $publicId = $appointment->json('data.id');

        DB::table('appointments')->where('public_id', $publicId)->update([
            'scheduled_for' => now()->subHour(),
        ]);

        $this->patchJson("/api/v1/appointments/{$publicId}/status", ['status' => 'no_show'], $this->withIdempotencyKey($this->bearer($staff)))->assertOk();

        $response = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'arrived'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');
    }

    public function test_marking_no_show_before_the_scheduled_time_is_rejected(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $appointment = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $publicId = $appointment->json('data.id');

        $response = $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'no_show'],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');
    }

    public function test_booking_twice_for_the_same_donor_returns_409_not_500(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $response = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDays(2)->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');
    }

    public function test_the_database_rejects_a_walk_in_marked_booked(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('appointments_walk_in_shape');

        DB::table('appointments')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'scheduled_for' => null,
            'status' => 'booked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cancelling_frees_the_slot_for_a_new_booking(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $first = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDay()->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $this->patchJson(
            "/api/v1/appointments/{$first->json('data.id')}/status",
            ['status' => 'cancelled'],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertOk();

        $second = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            ['scheduled_for' => now()->addDays(2)->toIso8601String()],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $second->assertStatus(201);
    }

    public function test_booking_for_a_merged_donor_returns_409(): void
    {
        $facility = Facility::factory()->create();
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source->forceFill(['merged_into_id' => $target->id, 'merged_at' => now()])->save();

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson(
            "/api/v1/donors/{$source->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staff))
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_MERGE_CONFLICT');
    }

    public function test_a_status_change_appears_in_the_audit_log(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $appointment = $this->postJson(
            "/api/v1/donors/{$donor->public_id}/appointments",
            [],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertStatus(201);

        $publicId = $appointment->json('data.id');

        $this->patchJson(
            "/api/v1/appointments/{$publicId}/status",
            ['status' => 'screened'],
            $this->withIdempotencyKey($this->bearer($staff))
        )->assertOk();

        $row = Appointment::query()->where('public_id', $publicId)->firstOrFail();

        $log = AuditLog::where('auditable_type', Appointment::class)
            ->where('auditable_id', $row->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('status', $log->changes['after']);
    }
}
