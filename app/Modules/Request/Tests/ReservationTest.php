<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Facility;
use App\Models\StockTransfer;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\Exceptions\ReservationRejected;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Exceptions\DomainException;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test. Lomba SUNGGUHAN (dua koneksi, dua proses)
 * ada di ReservationRaceTest; test di sini sekuensial.
 */
final class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['facility_id' => $this->facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($this->facility->id));
        $user->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function unit(string $expiresIn = '+5 days'): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $this->facility->id]);
        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => Carbon::parse($expiresIn),
            'status' => 'released',
            'released_at' => now()->subDays(29),
        ])->save();

        return $unit;
    }

    private function allocatedRequest(int $quantity = 1): BloodRequest
    {
        $request = app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            $quantity,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );

        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create(['facility_id' => $this->facility->id])->id);

        return $request;
    }

    private function reservations(): ReserveAllocatedUnits
    {
        return app(ReserveAllocatedUnits::class);
    }

    private function statusOf(BloodBatch $unit): string
    {
        return (string) DB::table('blood_batches')->where('id', $unit->id)->value('status');
    }

    private function allocationOf(BloodBatch $unit): BloodRequestAllocation
    {
        return BloodRequestAllocation::query()->where('blood_batch_id', $unit->id)->whereNull('released_at')->sole();
    }

    public function test_reserving_moves_allocated_units_to_reserved(): void
    {
        $a = $this->unit('+3 days');
        $b = $this->unit('+4 days');
        $request = $this->allocatedRequest(2);

        $reserved = $this->reservations()->handle($request);

        $this->assertCount(2, $reserved);
        $this->assertSame('reserved', $this->statusOf($a));
        $this->assertSame('reserved', $this->statusOf($b));
    }

    public function test_a_reserved_unit_disappears_from_the_available_stock_list(): void
    {
        $unit = $this->unit();
        $this->reservations()->handle($this->allocatedRequest());

        $ids = array_column(
            $this->getJson('/api/v1/blood-batches?filter[status]=released', $this->headers($this->staff()))->assertOk()->json('data'),
            'id',
        );

        $this->assertNotContains($unit->public_id, $ids);
    }

    public function test_a_reserved_unit_cannot_be_reserved_again(): void
    {
        $unit = $this->unit();
        $request = $this->allocatedRequest();
        $this->reservations()->handle($request);
        $reservedAt = $this->allocationOf($unit)->reserved_at;

        try {
            $this->reservations()->handle($request);
            $this->fail('A second reservation must be rejected.');
        } catch (ReservationRejected $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertSame('reserved', $this->statusOf($unit));
        $this->assertEquals($reservedAt, $this->allocationOf($unit)->reserved_at);
    }

    public function test_the_graph_rejects_reserving_a_unit_that_is_already_reserved(): void
    {
        // Baris alokasi masih "belum dipesan" tapi unitnya sudah reserved (keadaan
        // yang hanya muncul di tengah lomba): yang menolak adalah graf BatchStatus,
        // bukan pemeriksaan manual.
        $unit = $this->unit();
        $request = $this->allocatedRequest();
        DB::table('blood_batches')->where('id', $unit->id)->update(['status' => 'reserved']);

        try {
            $this->reservations()->handle($request);
            $this->fail('RESERVED -> RESERVED must be rejected by the graph.');
        } catch (DomainException $e) {
            $this->assertSame('BLOOD_BATCH_TRANSITION_REJECTED', $e->errorCode()->value);
            $this->assertStringContainsString('"reserved" to "reserved"', $e->getMessage());
        }

        $this->assertNull($this->allocationOf($unit)->reserved_at);
    }

    public function test_a_reserved_unit_cannot_be_transferred_to_another_facility(): void
    {
        $unit = $this->unit();
        $this->reservations()->handle($this->allocatedRequest());

        $other = Facility::factory()->create();
        $staff = User::factory()->create(['facility_id' => $other->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($other->id));
        $staff->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id], $this->headers($staff))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_reserving_sets_an_expiry_from_the_default_window(): void
    {
        $unit = $this->unit();
        $this->reservations()->handle($this->allocatedRequest());

        $allocation = $this->allocationOf($unit);
        $this->assertEquals(now(), $allocation->reserved_at);
        $this->assertEquals(now()->addHours(ReserveAllocatedUnits::DEFAULT_WINDOW_HOURS), $allocation->reservation_expires_at);
    }

    public function test_the_window_hours_option_overrides_the_default(): void
    {
        $unit = $this->unit();
        $request = $this->allocatedRequest();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/reservations", ['window_hours' => 6], $this->headers($this->staff()))
            ->assertCreated()
            ->assertJsonPath('data.0.unit_id', $unit->public_id)
            ->assertJsonPath('data.0.reservation_expires_at', now()->addHours(6)->toIso8601String());
    }

    public function test_a_window_above_one_week_is_422(): void
    {
        $unit = $this->unit();
        $request = $this->allocatedRequest();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/reservations", ['window_hours' => 169], $this->headers($this->staff()))
            ->assertStatus(422)
            ->assertJsonPath('error.details.window_hours.0.rule', 'max');

        $this->assertSame('released', $this->statusOf($unit));
    }

    public function test_the_loser_of_a_race_gets_409_not_a_database_error(): void
    {
        // Kalah lomba = reservasi kedua tiba setelah yang pertama commit. Lomba yang
        // benar-benar serentak dibuktikan ReservationRaceTest.
        $unit = $this->unit();
        $request = $this->allocatedRequest();
        $this->reservations()->handle($request);

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/reservations", [], $this->headers($this->staff()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $this->assertSame('reserved', $this->statusOf($unit));
    }

    public function test_unit_status_and_allocation_row_never_disagree(): void
    {
        // Unit kedua gagal di graf: reservasi unit PERTAMA pun harus batal, bukan setengah jadi.
        $first = $this->unit('+3 days');
        $second = $this->unit('+4 days');
        $request = $this->allocatedRequest(2);
        DB::table('blood_batches')->where('id', $second->id)->update(['status' => 'expired']);

        try {
            $this->reservations()->handle($request);
            $this->fail('An expired unit must not be reserved.');
        } catch (DomainException) {
            // diharapkan
        }

        $this->assertSame('released', $this->statusOf($first));
        $this->assertNull($this->allocationOf($first)->reserved_at);
        $this->assertNull($this->allocationOf($second)->reserved_at);
    }

    public function test_releasing_a_reservation_returns_the_unit_to_released(): void
    {
        $unit = $this->unit();
        $request = $this->allocatedRequest();
        $this->reservations()->handle($request);
        $allocation = $this->allocationOf($unit);

        $this->deleteJson("/api/v1/allocations/{$allocation->public_id}/reservation", ['reason' => 'Operasi ditunda.'], $this->headers($this->staff()))
            ->assertOk()
            ->assertJsonPath('data.reserved_at', null)
            ->assertJsonPath('data.reservation_release_reason', 'Operasi ditunda.')
            ->assertJsonPath('data.released_at', null);

        $this->assertSame('released', $this->statusOf($unit));
        // Alokasinya tetap hidup: hanya reservasinya yang dilepas.
        $this->assertNull($this->allocationOf($unit)->released_at);
    }

    public function test_releasing_an_unreserved_allocation_is_idempotent(): void
    {
        $unit = $this->unit();
        $this->allocatedRequest();
        $allocation = $this->allocationOf($unit);

        $this->reservations()->release($allocation, 'Tidak ada yang dipesan.');
        $this->reservations()->release($allocation, 'Tidak ada yang dipesan.');

        $this->assertSame('released', $this->statusOf($unit));
        $this->assertNull($allocation->fresh()?->reservation_release_reason);
    }

    public function test_a_reservation_on_an_issued_unit_is_cleared_without_forcing_the_status(): void
    {
        $unit = $this->unit();
        $this->reservations()->handle($this->allocatedRequest());
        DB::table('blood_batches')->where('id', $unit->id)->update(['status' => 'issued']);

        $this->reservations()->release($this->allocationOf($unit), 'Batal.');

        $this->assertSame('issued', $this->statusOf($unit));
        $this->assertNull($this->allocationOf($unit)->reserved_at);
    }

    public function test_releasing_a_reserved_allocation_also_returns_the_unit(): void
    {
        // Tanpa ini unit reserved tertinggal tanpa alokasi aktif: tak terlihat timeout,
        // tak bisa dialokasikan lagi.
        $unit = $this->unit();
        $this->reservations()->handle($this->allocatedRequest());
        $allocation = $this->allocationOf($unit);

        $this->deleteJson("/api/v1/allocations/{$allocation->public_id}", ['reason' => 'Pasien pulang.'], $this->headers($this->staff()))
            ->assertOk();

        $this->assertSame('released', $this->statusOf($unit));
        $this->assertSame(0, BloodRequestAllocation::query()->whereNull('released_at')->count());
    }

    public function test_the_database_rejects_a_reservation_without_an_expiry(): void
    {
        $unit = $this->unit();
        $this->allocatedRequest();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_request_allocations_reservation_shape');

        DB::table('blood_request_allocations')->where('blood_batch_id', $unit->id)->update(['reserved_at' => now()]);
    }

    public function test_the_database_rejects_an_expiry_without_a_reservation(): void
    {
        $unit = $this->unit();
        $this->allocatedRequest();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_request_allocations_reservation_shape');

        DB::table('blood_request_allocations')->where('blood_batch_id', $unit->id)->update(['reservation_expires_at' => now()]);
    }

    public function test_another_facility_cannot_reserve(): void
    {
        $this->unit();
        $request = $this->allocatedRequest();
        $other = Facility::factory()->create();
        $staff = User::factory()->create(['facility_id' => $other->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($other->id));
        $staff->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/reservations", [], $this->headers($staff))->assertForbidden();

        $this->assertSame(0, BloodRequestAllocation::query()->whereNotNull('reserved_at')->count());
    }
}
