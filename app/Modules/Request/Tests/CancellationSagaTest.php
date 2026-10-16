<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CancelBloodRequest;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\Exceptions\BloodRequestNotAllocatable;
use App\Modules\Request\Application\IssueAllocatedUnit;
use App\Modules\Request\Application\RecordCrossmatch;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\CrossmatchResult;
use App\Modules\Request\Domain\Events\UnitReservationLifted;
use App\Modules\Request\Domain\RequestStatus;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Saga pembatalan (kartu 360). Satu user terautentikasi per test; langkah peran
 * lain (alokasi, reservasi, crossmatch, penerbitan) lewat service.
 */
final class CancellationSagaTest extends TestCase
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

    private function staff(?Facility $facility = null): User
    {
        $facility ??= $this->facility;
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
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

    private function request(int $quantity): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
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
    }

    /** Permintaan yang seluruh unitnya sudah dialokasikan dan dipesan. */
    private function reservedRequest(int $quantity): BloodRequest
    {
        $request = $this->request($quantity);
        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create(['facility_id' => $this->facility->id])->id);
        app(ReserveAllocatedUnits::class)->handle($request);

        return $request;
    }

    private function statusOf(BloodBatch $unit): string
    {
        return (string) DB::table('blood_batches')->where('id', $unit->id)->value('status');
    }

    private function allocationOf(BloodBatch $unit): BloodRequestAllocation
    {
        return BloodRequestAllocation::query()->where('blood_batch_id', $unit->id)->latest('id')->firstOrFail();
    }

    private function issue(BloodBatch $unit): void
    {
        $allocation = $this->allocationOf($unit);
        app(RecordCrossmatch::class)->handle($allocation, CrossmatchResult::COMPATIBLE, User::factory()->create());
        app(IssueAllocatedUnit::class)->handle($allocation, User::factory()->create());
    }

    public function test_cancelling_returns_every_reserved_unit_to_released_stock(): void
    {
        $units = [$this->unit('+3 days'), $this->unit('+4 days'), $this->unit('+5 days')];
        $request = $this->reservedRequest(3);
        $this->assertSame(['reserved', 'reserved', 'reserved'], array_map($this->statusOf(...), $units));

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/cancel", ['reason' => 'Operasi dibatalkan.'], $this->headers($this->staff()))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Operasi dibatalkan.')
            ->assertJsonPath('data.cancelled_at', now()->toIso8601String());

        foreach ($units as $unit) {
            $this->assertSame('released', $this->statusOf($unit));

            $allocation = $this->allocationOf($unit);
            $this->assertNotNull($allocation->released_at);
            $this->assertSame('request_cancelled', $allocation->release_reason);
            $this->assertNull($allocation->reserved_at);
            $this->assertNull($allocation->reservation_expires_at);
            $this->assertSame('request_cancelled', $allocation->reservation_release_reason);
        }

        // Tidak ada unit yang mundur lebih jauh dari released.
        $this->assertSame(0, DB::table('blood_batches')
            ->whereIn('id', array_map(fn (BloodBatch $unit): int => $unit->id, $units))
            ->whereIn('status', ['quarantined', 'testing'])
            ->count());
    }

    public function test_an_unreserved_allocation_is_released_without_touching_the_unit(): void
    {
        $unit = $this->unit();
        $request = $this->request(1);
        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);

        app(CancelBloodRequest::class)->handle($request, 'Pasien dirujuk.');

        $this->assertSame('released', $this->statusOf($unit));
        $allocation = $this->allocationOf($unit);
        $this->assertNotNull($allocation->released_at);
        $this->assertNull($allocation->reservation_release_reason);
    }

    public function test_an_issued_unit_is_never_pulled_back_but_the_request_still_cancels(): void
    {
        $issued = $this->unit('+3 days');
        $b = $this->unit('+4 days');
        $c = $this->unit('+5 days');
        $request = $this->reservedRequest(3);
        $this->issue($issued);

        app(CancelBloodRequest::class)->handle($request, 'Sisa tidak dibutuhkan.');

        $this->assertSame(RequestStatus::CANCELLED, $request->fresh()?->status);
        $this->assertSame('issued', $this->statusOf($issued));
        $this->assertNull($this->allocationOf($issued)->released_at);
        $this->assertSame('released', $this->statusOf($b));
        $this->assertSame('released', $this->statusOf($c));
        $this->assertNotNull($this->allocationOf($b)->released_at);
        $this->assertNotNull($this->allocationOf($c)->released_at);
    }

    public function test_a_second_cancellation_is_409_and_changes_nothing(): void
    {
        $this->unit('+3 days');
        $this->unit('+4 days');
        $request = $this->reservedRequest(2);
        app(CancelBloodRequest::class)->handle($request, 'Pertama.');
        $before = DB::table('blood_request_allocations')->orderBy('id')->get(['id', 'updated_at', 'released_at', 'release_reason'])->all();

        $this->travel(5)->minutes();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/cancel", ['reason' => 'Kedua.'], $this->headers($this->staff()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_REQUEST_NOT_ALLOCATABLE');

        $this->assertEquals($before, DB::table('blood_request_allocations')->orderBy('id')->get(['id', 'updated_at', 'released_at', 'release_reason'])->all());
        $this->assertSame('Pertama.', $request->fresh()?->cancel_reason);
    }

    public function test_a_unit_that_expired_while_reserved_returns_as_expired_and_is_never_a_candidate(): void
    {
        $stale = $this->unit('+3 days');
        $fresh = $this->unit('+4 days');
        $request = $this->reservedRequest(2);
        $stale->forceFill(['expires_at' => now()->subDay()])->save();

        app(CancelBloodRequest::class)->handle($request, 'Batal.');

        $this->assertSame('expired', $this->statusOf($stale));
        $this->assertSame('released', $this->statusOf($fresh));

        // Permintaan lain dengan needed_by yang sudah lewat: penyaring expires_at >= needed_by
        // tidak menolong di sini, jadi yang menahannya keluar hanyalah status expired.
        $other = app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('-2 days'),
            'RM-'.Str::random(6),
        );

        $ids = array_column(
            $this->getJson("/api/v1/blood-requests/{$other->public_id}/candidates", $this->headers($this->staff()))->assertOk()->json('data.candidates'),
            'unit_id',
        );

        $this->assertNotContains($stale->public_id, $ids);
        $this->assertContains($fresh->public_id, $ids);
    }

    public function test_an_incompatible_crossmatch_releases_the_allocation_and_lowers_the_status(): void
    {
        $bad = $this->unit('+3 days');
        $good = $this->unit('+4 days');
        $request = $this->reservedRequest(2);
        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);

        $this->postJson(
            "/api/v1/allocations/{$this->allocationOf($bad)->public_id}/crossmatches",
            ['result' => 'incompatible', 'note' => 'Aglutinasi fase AHG.'],
            $this->headers($this->staff()),
        )->assertCreated();

        $allocation = $this->allocationOf($bad);
        $this->assertNotNull($allocation->released_at);
        $this->assertSame('crossmatch_incompatible', $allocation->release_reason);
        $this->assertNull($allocation->reserved_at);
        $this->assertSame('released', $this->statusOf($bad));
        $this->assertSame('reserved', $this->statusOf($good));
        $this->assertSame(RequestStatus::PARTIALLY_FULFILLED, BloodRequest::query()->findOrFail($request->id)->status);
    }

    public function test_an_inconclusive_crossmatch_releases_nothing(): void
    {
        $unit = $this->unit();
        $request = $this->reservedRequest(1);

        app(RecordCrossmatch::class)->handle($this->allocationOf($unit), CrossmatchResult::INCONCLUSIVE, User::factory()->create(), null, 'Sampel lisis.');

        $allocation = $this->allocationOf($unit);
        $this->assertNull($allocation->released_at);
        $this->assertNotNull($allocation->reserved_at);
        $this->assertSame('reserved', $this->statusOf($unit));
        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);
    }

    public function test_the_cancellation_is_atomic(): void
    {
        $units = [$this->unit('+3 days'), $this->unit('+4 days'), $this->unit('+5 days')];
        $request = $this->reservedRequest(3);
        $before = DB::table('blood_request_allocations')->orderBy('id')->get()->all();

        // Gagal setelah unit KEDUA dikembalikan: listener ini berjalan setelah
        // ReturnReservedUnitToStock, di transaksi yang sama.
        $lifted = 0;
        Event::listen(UnitReservationLifted::class, function () use (&$lifted): void {
            if (++$lifted === 2) {
                throw new RuntimeException('gagal di tengah saga');
            }
        });

        try {
            app(CancelBloodRequest::class)->handle($request, 'Batal.');
            $this->fail('Pembatalan seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertSame('gagal di tengah saga', $e->getMessage());
        }

        $this->assertEquals($before, DB::table('blood_request_allocations')->orderBy('id')->get()->all());
        $this->assertSame(['reserved', 'reserved', 'reserved'], array_map($this->statusOf(...), $units));
        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);
    }

    public function test_expiring_a_request_returns_its_units_and_is_terminal(): void
    {
        $unit = $this->unit();
        $request = $this->reservedRequest(1);
        $service = app(CancelBloodRequest::class);

        $service->expire($request);

        $fresh = $request->fresh();
        $this->assertSame(RequestStatus::EXPIRED, $fresh?->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame('released', $this->statusOf($unit));
        $this->assertSame('request_expired', $this->allocationOf($unit)->release_reason);

        $this->expectException(BloodRequestNotAllocatable::class);
        $service->handle($request, 'Terlambat.');
    }

    public function test_another_facility_cannot_cancel(): void
    {
        $this->unit();
        $request = $this->reservedRequest(1);

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/cancel", ['reason' => 'Batal.'], $this->headers($this->staff(Facility::factory()->create())))
            ->assertForbidden();

        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);
    }

    public function test_a_reason_is_required(): void
    {
        $request = $this->request(1);

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/cancel", [], $this->headers($this->staff()))
            ->assertStatus(422);

        $this->assertSame(RequestStatus::OPEN, $request->fresh()?->status);
    }

    public function test_the_database_rejects_a_half_written_cancellation(): void
    {
        $request = $this->request(1);

        foreach ([['cancelled_at' => now()], ['cancel_reason' => 'Batal.']] as $half) {
            try {
                DB::transaction(fn () => DB::table('blood_requests')->where('id', $request->id)->update($half));
                $this->fail('CHECK blood_requests_cancellation_shape seharusnya menolak.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('blood_requests_cancellation_shape', $e->getMessage());
            }
        }
    }
}
