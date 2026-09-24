<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\DonorEligibilityService;
use App\Modules\Donor\Application\RecordScreening;
use App\Modules\Donor\Domain\AppointmentStatus;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class RecordDonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    private function staffOf(Facility $facility): User
    {
        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        return $staff;
    }

    /**
     * Janji temu walk-in yang sudah sampai pada $status, ditulis langsung
     * seperti BookAppointment/TransitionAppointment menulisnya.
     */
    private function appointmentAt(Donor $donor, AppointmentStatus $status): Appointment
    {
        $appointment = new Appointment(['scheduled_for' => null]);

        $attributes = [
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'status' => $status,
            'arrived_at' => now()->subHour(),
        ];

        if ($status === AppointmentStatus::SCREENED) {
            $attributes['screened_at'] = now()->subMinutes(30);
        }

        $appointment->forceFill($attributes)->save();

        return $appointment;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'volume_ml' => 450,
            'started_at' => now()->subMinutes(15)->toIso8601String(),
            'completed_at' => now()->subMinutes(5)->toIso8601String(),
            'note' => 'Lengan kiri, tanpa keluhan.',
        ];
    }

    private function donate(Appointment $appointment, User $staff): TestResponse
    {
        return $this->postJson(
            "/api/v1/appointments/{$appointment->public_id}/donation",
            $this->payload(),
            $this->withIdempotencyKey($this->bearer($staff))
        );
    }

    public function test_a_screened_appointment_records_one_donation_and_returns_201(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $response = $this->donate($appointment, $this->staffOf($facility));

        $response->assertStatus(201)
            ->assertJsonPath('data.donor_id', $donor->public_id)
            ->assertJsonPath('data.appointment_id', $appointment->public_id)
            ->assertJsonPath('data.volume_ml', 450);

        $this->assertSame(1, Donation::query()->where('appointment_id', $appointment->id)->count());
        $this->assertArrayNotHasKey('id', Donation::query()->firstOrFail()->toArray());
    }

    public function test_the_appointment_moves_to_completed_with_completed_at_set(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->donate($appointment, $this->staffOf($facility))->assertStatus(201);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::COMPLETED, $appointment->status);
        $this->assertNotNull($appointment->completed_at);
    }

    public function test_the_donor_last_donation_date_and_count_are_updated(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'last_donation_date' => '2025-01-01',
        ]);
        $donor->forceFill(['donation_count' => 3])->save();
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $response = $this->donate($appointment, $this->staffOf($facility))->assertStatus(201);

        $donor->refresh();
        $completedOn = Donation::query()->where('public_id', $response->json('data.id'))->firstOrFail()->completed_at->toDateString();

        $this->assertSame($completedOn, $donor->last_donation_date?->toDateString());
        $this->assertSame(4, (int) $donor->donation_count);
    }

    public function test_the_latest_screening_of_the_appointment_is_linked(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $screening = app(RecordScreening::class)->handle($donor, [
            'haemoglobin_g_dl' => 14.0,
            'systolic_mmhg' => 120,
            'diastolic_mmhg' => 80,
            'pulse_bpm' => 72,
            'temperature_c' => 36.8,
            'weight_kg' => 70,
        ]);

        DB::table('donor_screenings')->where('public_id', $screening->public_id)->update([
            'appointment_id' => $appointment->id,
        ]);

        $this->donate($appointment, $this->staffOf($facility))
            ->assertStatus(201)
            ->assertJsonPath('data.screening_id', $screening->public_id);
    }

    public function test_donation_completed_is_published_with_the_right_ids(): void
    {
        Event::fake([DonationCompleted::class]);

        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $response = $this->donate($appointment, $this->staffOf($facility))->assertStatus(201);

        $donation = Donation::query()->where('public_id', $response->json('data.id'))->firstOrFail();

        Event::assertDispatchedTimes(DonationCompleted::class, 1);
        Event::assertDispatched(
            DonationCompleted::class,
            fn (DonationCompleted $event): bool => $event->donorId === $donor->id && $event->donationId === $donation->id
        );
    }

    /**
     * Keputusan 2: event() dipanggil DI DALAM transaksi. Tanpa Event::fake --
     * yang diuji justru pendengar sungguhan. Pendengar tambahan yang melempar
     * exception harus membatalkan seluruh donasi; begitu dimatikan, request
     * berikutnya berhasil.
     */
    public function test_a_failing_listener_rolls_back_the_whole_donation(): void
    {
        $failListener = true;

        Event::listen(DonationCompleted::class, function () use (&$failListener): void {
            if ($failListener) {
                throw new RuntimeException('Inventory tidak bisa mengkarantina kantong ini.');
            }
        });

        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'last_donation_date' => '2025-01-01',
        ]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);
        $staff = $this->staffOf($facility);

        $this->donate($appointment, $staff)->assertStatus(500);

        $this->assertSame(0, Donation::query()->count());
        $this->assertSame('2025-01-01', $donor->fresh()?->last_donation_date?->toDateString());
        $this->assertSame(0, (int) $donor->fresh()?->donation_count);
        $this->assertSame(AppointmentStatus::SCREENED, $appointment->fresh()?->status);
        $this->assertNull($appointment->fresh()?->completed_at);

        $failListener = false;

        $this->donate($appointment, $staff)->assertStatus(201);

        $this->assertSame(1, Donation::query()->count());
        $this->assertNotSame('2025-01-01', $donor->fresh()?->last_donation_date?->toDateString());
        $this->assertSame(1, (int) $donor->fresh()?->donation_count);
        $this->assertSame(AppointmentStatus::COMPLETED, $appointment->fresh()?->status);
    }

    public function test_an_appointment_that_is_not_yet_screened_is_rejected_with_409(): void
    {
        Event::fake([DonationCompleted::class]);

        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::ARRIVED);

        $this->donate($appointment, $this->staffOf($facility))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');

        $this->assertSame(0, Donation::query()->count());
        $this->assertSame(AppointmentStatus::ARRIVED, $appointment->fresh()?->status);
        $this->assertSame(0, (int) $donor->fresh()?->donation_count);
        Event::assertNotDispatched(DonationCompleted::class);
    }

    public function test_a_second_request_with_a_different_key_for_the_same_appointment_fails(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);
        $staff = $this->staffOf($facility);

        $this->donate($appointment, $staff)->assertStatus(201);

        $this->donate($appointment, $staff)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');

        $this->assertSame(1, Donation::query()->where('appointment_id', $appointment->id)->count());
        $this->assertSame(1, (int) $donor->fresh()?->donation_count);
    }

    /**
     * Keputusan 5: cek status di PHP menahan request berurutan, tapi dua
     * request yang lolos cek bersamaan hanya bisa ditahan database.
     */
    public function test_the_database_allows_only_one_donation_per_appointment(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $row = fn (): array => [
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'appointment_id' => $appointment->id,
            'volume_ml' => 450,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('donations')->insert($row());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donations_appointment_id_unique');

        DB::table('donations')->insert($row());
    }

    /** @return array<string, array{string, int}> */
    public static function intervalsBySex(): array
    {
        return [
            'male: 84 hari' => ['male', 84],
            'female: 112 hari' => ['female', 112],
        ];
    }

    /** Rantai Kartu 135: donasi yang tercatat harus benar-benar dibaca DonationIntervalRule. */
    #[DataProvider('intervalsBySex')]
    public function test_a_recorded_donation_makes_the_donor_not_eligible_until_the_interval_passes(string $sex, int $days): void
    {
        $this->travelTo('2026-09-25 10:00:00');

        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'sex' => $sex,
            'date_of_birth' => '1990-01-01',
            'weight_kg' => 70,
            'last_donation_date' => null,
        ]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->assertSame(EligibilityOutcome::ELIGIBLE, DonorEligibilityService::who()->for($donor->fresh())->outcome);

        $this->donate($appointment, $this->staffOf($facility))->assertStatus(201);

        $decision = DonorEligibilityService::who()->for($donor->fresh());

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertContains(
            EligibilityFindingCode::DONATION_INTERVAL_NOT_MET,
            array_map(fn (EligibilityFinding $finding): EligibilityFindingCode => $finding->code, $decision->findings)
        );
        $this->assertSame(
            now()->addDays($days)->toDateString(),
            $decision->retryAfterDate?->format('Y-m-d')
        );
    }

    public function test_the_database_rejects_a_zero_volume(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donations_volume_and_order');

        DB::table('donations')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'appointment_id' => $appointment->id,
            'volume_ml' => 0,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_completed_before_started(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donations_volume_and_order');

        DB::table('donations')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'appointment_id' => $appointment->id,
            'volume_ml' => 450,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_donation_and_the_donor_change_appear_in_the_audit_log(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $response = $this->donate($appointment, $this->staffOf($facility))->assertStatus(201);

        $donation = Donation::query()->where('public_id', $response->json('data.id'))->firstOrFail();

        $this->assertTrue(
            AuditLog::where('auditable_type', Donation::class)
                ->where('auditable_id', $donation->id)
                ->where('action', 'created')
                ->exists()
        );

        $donorLog = AuditLog::where('auditable_type', Donor::class)
            ->where('auditable_id', $donor->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($donorLog);
        $this->assertArrayHasKey('last_donation_date', $donorLog->changes['after']);
        $this->assertArrayHasKey('donation_count', $donorLog->changes['after']);
    }

    public function test_staff_of_another_facility_gets_403(): void
    {
        $facility = Facility::factory()->create();
        $otherFacility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->donate($appointment, $this->staffOf($otherFacility))->assertStatus(403);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_the_endpoint_requires_a_token(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->postJson(
            "/api/v1/appointments/{$appointment->public_id}/donation",
            $this->payload(),
            $this->withIdempotencyKey([])
        )->assertStatus(401);
    }

    public function test_the_endpoint_needs_an_idempotency_key(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->postJson(
            "/api/v1/appointments/{$appointment->public_id}/donation",
            $this->payload(),
            $this->bearer($this->staffOf($facility))
        )->assertStatus(400);

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_completed_before_started_is_rejected_with_422(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->postJson(
            "/api/v1/appointments/{$appointment->public_id}/donation",
            array_merge($this->payload(), [
                'started_at' => now()->subMinutes(5)->toIso8601String(),
                'completed_at' => now()->subMinutes(15)->toIso8601String(),
            ]),
            $this->withIdempotencyKey($this->bearer($this->staffOf($facility)))
        )->assertStatus(422)
            ->assertJsonPath('error.details.completed_at.0.rule', 'after_or_equal');

        $this->assertSame(0, Donation::query()->count());
    }

    public function test_a_volume_outside_the_device_range_is_rejected_with_422(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $appointment = $this->appointmentAt($donor, AppointmentStatus::SCREENED);

        $this->postJson(
            "/api/v1/appointments/{$appointment->public_id}/donation",
            array_merge($this->payload(), ['volume_ml' => 50]),
            $this->withIdempotencyKey($this->bearer($this->staffOf($facility)))
        )->assertStatus(422)
            ->assertJsonPath('error.details.volume_ml.0.rule', 'min');

        $this->assertSame(0, Donation::query()->count());
    }
}
