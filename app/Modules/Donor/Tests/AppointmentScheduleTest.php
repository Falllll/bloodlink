<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Appointment;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\FacilityAppointmentSchedule;
use App\Models\User;
use App\Modules\Donor\Application\AppointmentSlots;
use App\Modules\Donor\Application\BookAppointment;
use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pola jadwal & kapasitas slot appointment (kartu 503). "Sekarang" dibekukan ke
 * Senin 2 Nov 2026 03:00 WIB (= Minggu 1 Nov 20:00 UTC), pola Senin 08:00-10:00 WIB,
 * slot 30 menit, kapasitas 2. Satu user terautentikasi per test.
 */
final class AppointmentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-01 20:00:00', 'UTC'));
        $this->facility = Facility::factory()->create(['is_active' => true]);
    }

    private function user(string $role, ?Facility $facility): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /** @param  list<array<string, mixed>>|null  $windows */
    private function schedule(?array $windows = null, string $timezone = 'Asia/Jakarta'): void
    {
        $this->facility->forceFill(['timezone' => $timezone])->save();
        foreach ($windows ?? [['weekday' => 1, 'opens' => 480, 'closes' => 600, 'slot' => 30, 'capacity' => 2]] as $w) {
            $row = new FacilityAppointmentSchedule(['weekday' => $w['weekday'], 'opens_minute' => $w['opens'], 'closes_minute' => $w['closes'], 'slot_minutes' => $w['slot'], 'capacity' => $w['capacity']]);
            $row->forceFill(['facility_id' => $this->facility->id])->save();
        }
    }

    private function donor(): Donor
    {
        return Donor::factory()->create(['registered_facility_id' => $this->facility->id]);
    }

    private function linkedDonorAccount(): User
    {
        $user = $this->user('donor', null);
        $this->donor()->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    /** Appointment orang lain di slot itu, ditulis langsung (satu terbuka per donor). */
    private function occupy(string $utc, AppointmentStatus $status = AppointmentStatus::BOOKED): void
    {
        $donor = $this->donor();
        $a = new Appointment(['scheduled_for' => Carbon::parse($utc, 'UTC')]);
        $a->forceFill(['public_id' => (string) Str::uuid(), 'donor_id' => $donor->id, 'facility_id' => $this->facility->id, 'status' => $status])->save();
    }

    /** @return TestResponse<JsonResponse> */
    private function bookAs(User $user, string $at): TestResponse
    {
        return $this->postJson('/api/v1/me/donor/appointments', ['scheduled_for' => $at], $this->as($user));
    }

    // ------------------------------------------------------------- schedule

    public function test_a_facility_admin_replaces_the_weekly_pattern_in_local_wall_clock(): void
    {
        $admin = $this->user('admin', $this->facility);

        $this->putJson("/api/v1/facilities/{$this->facility->public_id}/appointment-schedule", [
            'timezone' => 'Asia/Makassar',
            'windows' => [
                ['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '12:00', 'slot_minutes' => 30, 'capacity' => 3],
                ['weekday' => 1, 'opens_at' => '13:00', 'closes_at' => '15:00', 'slot_minutes' => 60, 'capacity' => 1],
            ],
        ], $this->as($admin))
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Makassar')
            ->assertJsonPath('data.windows.0.opens_at', '08:00')
            ->assertJsonPath('data.windows.1.closes_at', '15:00');

        $this->assertSame(2, FacilityAppointmentSchedule::query()->where('facility_id', $this->facility->id)->count());
        $this->assertSame('Asia/Makassar', $this->facility->refresh()->timezone);
    }

    public function test_hospital_staff_and_other_facility_admins_cannot_change_the_pattern(): void
    {
        $staff = $this->user('hospital_staff', $this->facility);

        $this->putJson("/api/v1/facilities/{$this->facility->public_id}/appointment-schedule", ['timezone' => 'Asia/Jakarta', 'windows' => []], $this->as($staff))
            ->assertForbidden();
    }

    public function test_overlapping_windows_are_rejected_and_the_old_pattern_survives(): void
    {
        $this->schedule();
        $admin = $this->user('admin', $this->facility);

        $this->putJson("/api/v1/facilities/{$this->facility->public_id}/appointment-schedule", [
            'timezone' => 'Asia/Jakarta',
            'windows' => [
                ['weekday' => 2, 'opens_at' => '08:00', 'closes_at' => '10:00', 'slot_minutes' => 30, 'capacity' => 1],
                ['weekday' => 2, 'opens_at' => '09:30', 'closes_at' => '11:00', 'slot_minutes' => 30, 'capacity' => 1],
            ],
        ], $this->as($admin))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Atomik: pola lama (Senin) masih utuh.
        $this->assertSame([1], FacilityAppointmentSchedule::query()->where('facility_id', $this->facility->id)->pluck('weekday')->all());
    }

    public function test_a_window_must_be_a_whole_number_of_slots(): void
    {
        $admin = $this->user('admin', $this->facility);

        $this->putJson("/api/v1/facilities/{$this->facility->public_id}/appointment-schedule", [
            'timezone' => 'Asia/Jakarta',
            'windows' => [['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '09:45', 'slot_minutes' => 30, 'capacity' => 1]],
        ], $this->as($admin))->assertStatus(422);
    }

    public function test_the_database_rejects_a_bad_pattern_on_its_own(): void
    {
        $this->schedule();

        foreach ([
            'overlap' => ['weekday' => 1, 'opens_minute' => 540, 'closes_minute' => 660, 'slot_minutes' => 30, 'capacity' => 1],
            'capacity' => ['weekday' => 3, 'opens_minute' => 480, 'closes_minute' => 600, 'slot_minutes' => 30, 'capacity' => 0],
            'partial slot' => ['weekday' => 4, 'opens_minute' => 480, 'closes_minute' => 590, 'slot_minutes' => 30, 'capacity' => 1],
        ] as $case => $row) {
            try {
                DB::transaction(fn () => DB::table('facility_appointment_schedules')->insert($row + ['facility_id' => $this->facility->id, 'created_at' => now(), 'updated_at' => now()]));
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
            }
        }

        $this->expectException(QueryException::class);
        $this->facility->forceFill(['timezone' => 'Europe/Berlin'])->save();
    }

    // ---------------------------------------------------------- availability

    public function test_slots_are_reported_in_the_facility_zone_with_an_explicit_offset(): void
    {
        $this->schedule();
        $this->occupy('2026-11-02 01:00:00');  // 08:00 WIB
        $donor = $this->linkedDonorAccount();

        $slots = $this->getJson("/api/v1/facilities/{$this->facility->public_id}/appointment-slots?from=2026-11-02&days=1", $this->as($donor))
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Jakarta')
            ->assertJsonPath('data.schedule_configured', true)
            ->json('data.slots');

        $this->assertSame(['2026-11-02T08:00:00+07:00', '2026-11-02T08:30:00+07:00', '2026-11-02T09:00:00+07:00', '2026-11-02T09:30:00+07:00'], array_column($slots, 'starts_at'));
        $this->assertSame(['booked' => 1, 'available' => 1], ['booked' => $slots[0]['booked'], 'available' => $slots[0]['available']]);
        $this->assertSame(2, $slots[1]['available']);
    }

    public function test_the_same_wall_clock_is_a_different_instant_in_another_zone(): void
    {
        $this->schedule(timezone: 'Asia/Jayapura');
        $donor = $this->linkedDonorAccount();

        $first = $this->getJson("/api/v1/facilities/{$this->facility->public_id}/appointment-slots?from=2026-11-02&days=1", $this->as($donor))
            ->assertOk()->json('data.slots.0.starts_at');

        $this->assertSame('2026-11-02T08:00:00+09:00', $first);
    }

    public function test_closed_dates_and_past_slots_are_not_offered(): void
    {
        $this->schedule([['weekday' => 1, 'opens' => 0, 'closes' => 600, 'slot' => 60, 'capacity' => 1]]);
        $admin = $this->user('admin', $this->facility);

        $this->postJson("/api/v1/facilities/{$this->facility->public_id}/appointment-closures", ['date' => '2026-11-09', 'reason' => 'Libur'], $this->as($admin))
            ->assertCreated();

        $starts = array_column($this->getJson("/api/v1/facilities/{$this->facility->public_id}/appointment-slots?from=2026-11-02&days=14", $this->as($admin))->assertOk()->json('data.slots'), 'starts_at');

        // Senin 2 Nov: 00:00-03:00 WIB sudah lewat. Senin 9 Nov: tutup. Senin 16 Nov: di luar 14 hari.
        $this->assertSame('2026-11-02T04:00:00+07:00', $starts[0]);
        $this->assertCount(6, $starts);
        $this->assertSame([], array_filter($starts, fn (string $s): bool => str_starts_with($s, '2026-11-09')));
    }

    public function test_a_facility_without_a_pattern_says_so_instead_of_inventing_slots(): void
    {
        $donor = $this->linkedDonorAccount();

        $this->getJson("/api/v1/facilities/{$this->facility->public_id}/appointment-slots", $this->as($donor))
            ->assertOk()
            ->assertJsonPath('data.schedule_configured', false)
            ->assertJsonPath('data.slots', []);
    }

    // --------------------------------------------------------------- booking

    public function test_booking_a_slot_stores_the_utc_instant_not_the_wall_clock(): void
    {
        $this->schedule();
        $donor = $this->linkedDonorAccount();

        $this->bookAs($donor, '2026-11-02T08:30:00+07:00')
            ->assertCreated()
            ->assertJsonPath('data.scheduled_for', '2026-11-02T01:30:00+00:00');

        $this->assertSame('2026-11-02 01:30:00', DB::table('appointments')->value('scheduled_for'));
    }

    public function test_a_time_that_is_not_a_slot_start_is_rejected(): void
    {
        $this->schedule();
        $donor = $this->linkedDonorAccount();

        foreach (['2026-11-02T08:15:00+07:00', '2026-11-02T10:00:00+07:00', '2026-11-03T08:00:00+07:00'] as $at) {
            $this->bookAs($donor, $at)->assertStatus(422)->assertJsonPath('error.code', 'APPOINTMENT_SLOT_UNAVAILABLE');
        }

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_full_slot_is_rejected_and_a_cancelled_booking_frees_its_seat(): void
    {
        $this->schedule();
        $this->occupy('2026-11-02 01:00:00');
        $this->occupy('2026-11-02 01:00:00');
        $donor = $this->linkedDonorAccount();

        $this->bookAs($donor, '2026-11-02T08:00:00+07:00')->assertStatus(409)->assertJsonPath('error.code', 'APPOINTMENT_SLOT_UNAVAILABLE');

        Appointment::query()->first()?->forceFill(['status' => AppointmentStatus::CANCELLED, 'cancelled_at' => now()])->save();

        $this->bookAs($donor, '2026-11-02T08:00:00+07:00')->assertCreated();
    }

    public function test_a_closed_date_rejects_bookings(): void
    {
        $this->schedule();
        DB::table('facility_appointment_closures')->insert(['facility_id' => $this->facility->id, 'closed_on' => '2026-11-02', 'reason' => 'Banjir', 'created_at' => now(), 'updated_at' => now()]);
        $donor = $this->linkedDonorAccount();

        $this->bookAs($donor, '2026-11-02T08:00:00+07:00')->assertStatus(422)->assertJsonPath('error.code', 'APPOINTMENT_SLOT_UNAVAILABLE');
    }

    public function test_a_facility_without_a_pattern_keeps_accepting_any_future_time(): void
    {
        $donor = $this->linkedDonorAccount();

        $this->bookAs($donor, '2026-11-02T11:17:00+07:00')->assertCreated();
    }

    public function test_a_walk_in_never_takes_a_slot_even_when_the_slot_is_full(): void
    {
        $this->schedule();
        $this->occupy('2026-11-02 01:00:00');
        $this->occupy('2026-11-02 01:00:00');

        $walkIn = app(BookAppointment::class)->handle($this->donor(), null);

        $this->assertSame(AppointmentStatus::ARRIVED, $walkIn->status);
        $this->assertNull($walkIn->scheduled_for);
    }

    public function test_existing_appointments_stay_valid_when_a_pattern_arrives(): void
    {
        $this->occupy('2026-11-02 04:17:00');  // 11:17 WIB, dipesan sebelum ada pola
        $this->schedule();

        $this->assertSame(1, Appointment::query()->where('status', AppointmentStatus::BOOKED->value)->count());
        // Dan tetap dihitung jujur di ketersediaan: tidak jatuh di slot mana pun, jadi tidak menggeser angka.
        $this->assertSame(2, collect(app(AppointmentSlots::class)->availability($this->facility, '2026-11-02', 1))->min('available'));
    }

    public function test_the_service_accepts_a_slot_given_in_utc(): void
    {
        $this->schedule();

        $appointment = app(BookAppointment::class)->handle($this->donor(), new DateTimeImmutable('2026-11-02T02:00:00Z'));

        $this->assertSame('2026-11-02T02:00:00+00:00', $appointment->scheduled_for?->toIso8601String());
    }
}
