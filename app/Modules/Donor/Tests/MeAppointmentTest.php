<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Appointment;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: dalam satu test, guard Sanctum tetap
 * memegang user request pertama meski token berganti. Baris milik donor lain
 * karenanya ditulis langsung, bukan lewat token kedua.
 */
final class MeAppointmentTest extends TestCase
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
     * Key baru per request: EnsureIdempotency global, dan key yang dipakai ulang
     * dengan body berbeda membalas 422 IDEMPOTENCY_KEY_REUSED.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withIdempotencyKey(array $headers): array
    {
        return array_merge($headers, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** Akun donor seperti yang dibuat AuthController::register(): tanpa fasilitas. */
    private function donorAccount(): User
    {
        $user = User::factory()->create(['facility_id' => null]);
        $this->assignRole($user, 'donor');

        return $user;
    }

    /** Penautan user_id dikerjakan staf; di sini ditulis langsung karena di luar $fillable. */
    private function linkedDonor(User $user): Donor
    {
        $donor = Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
        $donor->forceFill(['user_id' => $user->id])->save();

        return $donor;
    }

    private function unlinkedDonor(): Donor
    {
        return Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
    }

    /**
     * Baris ditulis langsung, bukan dipesan lewat endpoint:
     * appointments_one_open_per_donor hanya mengizinkan satu baris terbuka.
     * $scheduledFor null = walk-in yang dicatat petugas.
     */
    private function appointmentFor(Donor $donor, AppointmentStatus $status, ?Carbon $scheduledFor, ?Carbon $arrivedAt = null): Appointment
    {
        $appointment = new Appointment(['scheduled_for' => $scheduledFor]);
        $appointment->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'status' => $status,
            'arrived_at' => $arrivedAt ?? ($status === AppointmentStatus::BOOKED ? null : ($scheduledFor ?? now())),
            'completed_at' => $status === AppointmentStatus::COMPLETED ? ($arrivedAt ?? $scheduledFor ?? now()) : null,
        ])->save();

        return $appointment;
    }

    private function completedAt(Donor $donor, Carbon $at, bool $walkIn = false): Appointment
    {
        return $this->appointmentFor($donor, AppointmentStatus::COMPLETED, $walkIn ? null : $at, $at);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function book(array $headers, array $body): TestResponse
    {
        return $this->postJson('/api/v1/me/donor/appointments', $body, $this->withIdempotencyKey($headers));
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function transition(array $headers, string $publicId, string $status): TestResponse
    {
        return $this->patchJson(
            "/api/v1/me/donor/appointments/{$publicId}/status",
            ['status' => $status],
            $this->withIdempotencyKey($headers),
        );
    }

    private function future(): string
    {
        return now()->addDays(3)->startOfHour()->toIso8601String();
    }

    public function test_a_linked_donor_books_their_own_appointment(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);

        $response = $this->book($this->bearer($user), ['scheduled_for' => $this->future(), 'note' => 'Pagi saja.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.donor_id', $donor->public_id)
            ->assertJsonPath('data.arrived_at', null)
            ->assertJsonPath('data.note', 'Pagi saja.');

        $row = Appointment::query()->where('public_id', $response->json('data.id'))->sole();
        $this->assertSame($user->id, $row->created_by);
    }

    public function test_the_booked_row_belongs_to_the_donors_registered_facility(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);

        $response = $this->book($this->bearer($user), ['scheduled_for' => $this->future()])->assertCreated();

        $this->assertDatabaseHas('appointments', [
            'public_id' => $response->json('data.id'),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
        ]);
    }

    public function test_a_booking_without_a_scheduled_for_is_422_not_a_walk_in(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);
        $headers = $this->bearer($user);

        $this->book($headers, [])
            ->assertStatus(422)
            ->assertJsonPath('error.details.scheduled_for.0.rule', 'required');
        $this->book($headers, ['scheduled_for' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.details.scheduled_for.0.rule', 'required');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_booking_in_the_past_is_422(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);

        $this->book($this->bearer($user), ['scheduled_for' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonPath('error.details.scheduled_for.0.rule', 'after');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_an_unknown_body_key_is_422(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);

        $this->book($this->bearer($user), ['scheduled_for' => $this->future(), 'status' => 'arrived', 'facility_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error.details.status.0.rule', 'not_allowed')
            ->assertJsonPath('error.details.facility_id.0.rule', 'not_allowed');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_second_open_booking_is_409_already_open(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);
        $headers = $this->bearer($user);

        $first = $this->book($headers, ['scheduled_for' => $this->future()])->assertCreated();

        $this->book($headers, ['scheduled_for' => now()->addDays(5)->toIso8601String()])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_TRANSITION_REJECTED');

        // Jalur reschedule: batal dulu, baru pesan lagi -- urutannya dipaksa index.
        $this->transition($headers, $first->json('data.id'), 'cancelled')->assertOk();
        $this->book($headers, ['scheduled_for' => now()->addDays(5)->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'booked');
    }

    public function test_a_donor_cancels_their_own_appointment(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $appointment = $this->appointmentFor($donor, AppointmentStatus::BOOKED, now()->addDays(2));

        $response = $this->transition($this->bearer($user), $appointment->public_id, 'cancelled')
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->public_id)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertSame(AppointmentStatus::CANCELLED, $appointment->fresh()?->status);
    }

    public function test_a_donor_cannot_transition_to_arrived_or_completed(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $appointment = $this->appointmentFor($donor, AppointmentStatus::BOOKED, now()->addDays(2));
        $headers = $this->bearer($user);

        foreach (['arrived', 'screened', 'completed', 'no_show'] as $status) {
            $this->transition($headers, $appointment->public_id, $status)
                ->assertStatus(422)
                ->assertJsonPath('error.details.status.0.rule', 'in');
        }

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::BOOKED, $fresh?->status);
        $this->assertNull($fresh->arrived_at);
    }

    public function test_another_donors_appointment_is_404_not_403(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);
        $theirs = $this->appointmentFor($this->unlinkedDonor(), AppointmentStatus::BOOKED, now()->addDays(2));

        $this->transition($this->bearer($user), $theirs->public_id, 'cancelled')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_cancelling_another_donors_appointment_leaves_that_row_untouched(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);
        $theirs = $this->appointmentFor($this->unlinkedDonor(), AppointmentStatus::BOOKED, now()->addDays(2));
        $before = DB::table('appointments')->where('id', $theirs->id)->first();

        $this->transition($this->bearer($user), $theirs->public_id, 'cancelled')->assertNotFound();

        $this->assertEquals($before, DB::table('appointments')->where('id', $theirs->id)->first());
    }

    public function test_a_malformed_appointment_id_is_404_not_500(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);

        $this->transition($this->bearer($user), 'bukan-uuid', 'cancelled')->assertNotFound();
    }

    public function test_the_list_contains_only_the_donors_own_appointments(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $older = $this->completedAt($donor, Carbon::parse('2026-01-10 09:00:00'));
        $newer = $this->appointmentFor($donor, AppointmentStatus::BOOKED, now()->addDays(2));
        $this->appointmentFor($this->unlinkedDonor(), AppointmentStatus::BOOKED, now()->addDays(4));

        $response = $this->getJson('/api/v1/me/donor/appointments', $this->bearer($user))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // scheduled_for desc: yang akan datang di atas riwayat.
        $this->assertSame([$newer->public_id, $older->public_id], array_column($response->json('data'), 'id'));
    }

    public function test_the_second_page_is_reachable_with_the_cursor_from_the_first_page(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $start = Carbon::parse('2026-01-01 09:00:00');

        // 25 > 20 per halaman, jadi halaman 2 memang ada.
        for ($i = 0; $i < 25; $i++) {
            $this->completedAt($donor, $start->copy()->addDays($i));
        }

        $headers = $this->bearer($user);

        $first = $this->getJson('/api/v1/me/donor/appointments', $headers)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.has_more', true);

        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/api/v1/me/donor/appointments?cursor='.$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
    }

    /**
     * Walk-in petugas tidak punya scheduled_for. Kursor yang membawa null akan
     * menjadi `scheduled_for < NULL` dan sisa riwayat hilang tanpa error.
     */
    public function test_walk_in_history_is_not_lost_across_pages(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $start = Carbon::parse('2026-01-01 09:00:00');

        $expected = [];
        for ($i = 0; $i < 25; $i++) {
            $expected[] = $this->completedAt($donor, $start->copy()->addDays($i), walkIn: $i % 2 === 0)->public_id;
        }

        $headers = $this->bearer($user);

        $first = $this->getJson('/api/v1/me/donor/appointments', $headers)->assertOk()->assertJsonCount(20, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getJson('/api/v1/me/donor/appointments?cursor='.$cursor, $headers)->assertOk();

        $seen = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];

        // Terbaru di atas, walk-in diurutkan menurut waktu kedatangannya.
        $this->assertSame(array_reverse($expected), $seen);
    }

    public function test_a_page_boundary_on_a_walk_in_still_reaches_the_rest(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $start = Carbon::parse('2026-01-01 09:00:00');

        for ($i = 0; $i < 25; $i++) {
            $this->completedAt($donor, $start->copy()->addDays($i), walkIn: true);
        }

        $headers = $this->bearer($user);

        $cursor = $this->getJson('/api/v1/me/donor/appointments', $headers)->assertOk()->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/v1/me/donor/appointments?cursor='.$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_an_account_without_a_linked_donor_gets_404_not_linked(): void
    {
        $user = $this->donorAccount();
        $someone = $this->appointmentFor($this->unlinkedDonor(), AppointmentStatus::BOOKED, now()->addDays(2));
        $headers = $this->bearer($user);

        // Bukan daftar kosong: klien harus tahu akunnya belum tertaut.
        $this->getJson('/api/v1/me/donor/appointments', $headers)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
        $this->book($headers, ['scheduled_for' => $this->future()])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
        $this->transition($headers, $someone->public_id, 'cancelled')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(AppointmentStatus::BOOKED, $someone->fresh()?->status);
    }

    public function test_the_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/me/donor/appointments')->assertUnauthorized();
        $this->postJson('/api/v1/me/donor/appointments', ['scheduled_for' => $this->future()], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnauthorized();
        $this->patchJson('/api/v1/me/donor/appointments/'.Str::uuid().'/status', ['status' => 'cancelled'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnauthorized();
    }

    public function test_a_post_without_an_idempotency_key_is_400(): void
    {
        $user = $this->donorAccount();
        $this->linkedDonor($user);

        $this->postJson('/api/v1/me/donor/appointments', ['scheduled_for' => $this->future()], $this->bearer($user))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Appointment::query()->count());
    }
}
