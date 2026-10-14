<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\StockTransfer;
use App\Models\User;
use App\Modules\Inventory\Application\Exceptions\StockTransferRejected;
use App\Modules\Inventory\Application\RequestStockTransfer;
use App\Modules\Inventory\Application\TransitionStockTransfer;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Satu aktor HTTP per test: guard Sanctum memegang user dari request pertama
 * meski tokennya berganti. Langkah milik peran lain dijalankan lewat service.
 */
final class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Facility $owner;

    private Facility $requester;

    private Facility $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);

        $this->owner = Facility::factory()->create();
        $this->requester = Facility::factory()->create();
        $this->third = Facility::factory()->create();
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function staffOf(Facility $facility, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /**
     * Key baru per request: EnsureIdempotency global.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withKey(array $headers): array
    {
        return $headers + ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** blood_batches_released_shape: released wajib membawa released_at. */
    private function unitAt(Facility $facility, BatchStatus $status = BatchStatus::RELEASED): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['facility_id' => $facility->id]);
        $unit->forceFill([
            'status' => $status,
            'released_at' => in_array($status, [BatchStatus::RELEASED, BatchStatus::RESERVED, BatchStatus::ISSUED], true) ? now() : null,
            'discard_reason' => $status === BatchStatus::DISCARDED ? 'Kantong bocor.' : null,
        ])->save();

        return $unit;
    }

    private function requested(BloodBatch $unit): StockTransfer
    {
        return app(RequestStockTransfer::class)->handle($unit, $this->requester->id, User::factory()->create()->id, 'Stok O- habis.');
    }

    private function advance(StockTransfer $transfer, TransferStatus ...$steps): StockTransfer
    {
        foreach ($steps as $step) {
            $transfer = app(TransitionStockTransfer::class)->handle($transfer, $step);
        }

        return $transfer;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function act(array $headers, StockTransfer $transfer, string $action, array $body = []): TestResponse
    {
        $url = "/api/v1/stock-transfers/{$transfer->public_id}/{$action}";

        return $action === 'decision'
            ? $this->patchJson($url, $body, $this->withKey($headers))
            : $this->postJson($url, $body, $this->withKey($headers));
    }

    private function facilityOf(BloodBatch $unit): int
    {
        return (int) DB::table('blood_batches')->where('id', $unit->id)->value('facility_id');
    }

    public function test_a_facility_requests_a_unit_from_another_facility(): void
    {
        $unit = $this->unitAt($this->owner);
        $staff = $this->staffOf($this->requester);

        $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id, 'reason' => 'Stok O- habis.'], $this->withKey($this->bearer($staff)))
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.unit_id', $unit->public_id)
            ->assertJsonPath('data.from_facility_id', $this->owner->public_id)
            ->assertJsonPath('data.to_facility_id', $this->requester->public_id)
            ->assertJsonPath('data.reason', 'Stok O- habis.')
            ->assertJsonMissingPath('data.requested_by');

        $this->assertDatabaseHas('stock_transfers', [
            'blood_batch_id' => $unit->id,
            'from_facility_id' => $this->owner->id,
            'to_facility_id' => $this->requester->id,
            'requested_by' => $staff->id,
        ]);
    }

    public function test_the_full_lifecycle_moves_the_unit_exactly_at_receive(): void
    {
        $unit = $this->unitAt($this->owner);
        $transfer = $this->requested($unit);

        foreach ([TransferStatus::APPROVED, TransferStatus::SHIPPED] as $step) {
            $this->advance($transfer, $step);
            $this->assertSame($this->owner->id, $this->facilityOf($unit), "still at origin after {$step->value}");
        }

        $this->advance($transfer, TransferStatus::RECEIVED);

        $this->assertSame($this->requester->id, $this->facilityOf($unit));
        $fresh = $transfer->fresh();
        $this->assertSame(TransferStatus::RECEIVED, $fresh?->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNotNull($fresh->shipped_at);
        $this->assertNotNull($fresh->received_at);
        // Status unit tidak pernah dipakai sebagai penanda transfer.
        $this->assertSame(BatchStatus::RELEASED, $unit->fresh()?->status);
    }

    public function test_after_receipt_the_requesting_facility_can_read_the_unit(): void
    {
        $unit = $this->unitAt($this->owner);
        $this->advance($this->requested($unit), TransferStatus::APPROVED, TransferStatus::SHIPPED, TransferStatus::RECEIVED);

        $this->getJson("/api/v1/blood-batches/{$unit->public_id}", $this->bearer($this->staffOf($this->requester)))
            ->assertOk()
            ->assertJsonPath('data.facility_id', $this->requester->public_id);
    }

    public function test_after_receipt_the_origin_facility_can_no_longer_read_the_unit(): void
    {
        $unit = $this->unitAt($this->owner);
        $this->advance($this->requested($unit), TransferStatus::APPROVED, TransferStatus::SHIPPED, TransferStatus::RECEIVED);

        // 403, bukan 404: begitulah BloodBatchController memperlakukan unit fasilitas lain.
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}", $this->bearer($this->staffOf($this->owner)))->assertForbidden();
    }

    public function test_the_unit_stays_at_the_origin_facility_until_received(): void
    {
        $unit = $this->unitAt($this->owner);
        $this->advance($this->requested($unit), TransferStatus::APPROVED, TransferStatus::SHIPPED);
        $headers = $this->bearer($this->staffOf($this->requester));

        $this->assertSame($this->owner->id, $this->facilityOf($unit));
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}", $headers)->assertForbidden();
        $this->assertNotContains(
            $unit->public_id,
            array_column($this->getJson('/api/v1/blood-batches', $headers)->assertOk()->json('data'), 'id'),
        );
    }

    public function test_a_second_open_transfer_for_the_same_unit_is_409(): void
    {
        $unit = $this->unitAt($this->owner);
        $this->requested($unit);

        $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id], $this->withKey($this->bearer($this->staffOf($this->third))))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $this->assertSame(1, StockTransfer::query()->count());
    }

    public function test_a_transfer_to_the_units_own_facility_is_rejected(): void
    {
        $unit = $this->unitAt($this->owner);

        $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id], $this->withKey($this->bearer($this->staffOf($this->owner))))
            ->assertForbidden();

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_the_service_refuses_a_same_facility_transfer_before_the_database_does(): void
    {
        $unit = $this->unitAt($this->owner);

        $this->expectException(StockTransferRejected::class);

        app(RequestStockTransfer::class)->handle($unit, $this->owner->id, User::factory()->create()->id, null);
    }

    public function test_only_released_units_can_be_transferred(): void
    {
        $headers = $this->bearer($this->staffOf($this->requester));

        foreach ([BatchStatus::QUARANTINED, BatchStatus::DISCARDED, BatchStatus::ISSUED] as $status) {
            $unit = $this->unitAt($this->owner, $status);

            $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id], $this->withKey($headers))
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');
        }

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_shipping_before_approval_is_409(): void
    {
        $transfer = $this->requested($this->unitAt($this->owner));

        $this->act($this->bearer($this->staffOf($this->owner)), $transfer, 'ship')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $this->assertSame(TransferStatus::REQUESTED, $transfer->fresh()?->status);
    }

    public function test_a_shipped_transfer_can_no_longer_be_cancelled(): void
    {
        $transfer = $this->advance($this->requested($this->unitAt($this->owner)), TransferStatus::APPROVED, TransferStatus::SHIPPED);

        $this->act($this->bearer($this->staffOf($this->requester)), $transfer, 'cancel')->assertStatus(409);

        $this->assertSame(TransferStatus::SHIPPED, $transfer->fresh()?->status);
    }

    public function test_the_owner_facility_approves(): void
    {
        $transfer = $this->requested($this->unitAt($this->owner));
        $staff = $this->staffOf($this->owner);

        $this->act($this->bearer($staff), $transfer, 'decision', ['status' => 'approved', 'decision_note' => 'Ada 4 kantong O-.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_note', 'Ada 4 kantong O-.');

        $this->assertSame($staff->id, $transfer->fresh()?->decided_by);
    }

    public function test_the_requester_cannot_decide_its_own_request(): void
    {
        $transfer = $this->requested($this->unitAt($this->owner));

        $this->act($this->bearer($this->staffOf($this->requester)), $transfer, 'decision', ['status' => 'approved'])->assertForbidden();

        $this->assertSame(TransferStatus::REQUESTED, $transfer->fresh()?->status);
    }

    public function test_a_decision_only_accepts_approved_or_rejected(): void
    {
        $transfer = $this->requested($this->unitAt($this->owner));

        $this->act($this->bearer($this->staffOf($this->owner)), $transfer, 'decision', ['status' => 'received'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.status.0.rule', 'in');

        $this->assertSame(TransferStatus::REQUESTED, $transfer->fresh()?->status);
    }

    public function test_the_requester_receives(): void
    {
        $unit = $this->unitAt($this->owner);
        $transfer = $this->advance($this->requested($unit), TransferStatus::APPROVED, TransferStatus::SHIPPED);

        $this->act($this->bearer($this->staffOf($this->requester)), $transfer, 'receive')
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertSame($this->requester->id, $this->facilityOf($unit));
    }

    public function test_the_owner_cannot_receive(): void
    {
        $unit = $this->unitAt($this->owner);
        $transfer = $this->advance($this->requested($unit), TransferStatus::APPROVED, TransferStatus::SHIPPED);

        $this->act($this->bearer($this->staffOf($this->owner)), $transfer, 'receive')->assertForbidden();

        $this->assertSame(TransferStatus::SHIPPED, $transfer->fresh()?->status);
        $this->assertSame($this->owner->id, $this->facilityOf($unit));
    }

    public function test_a_third_facility_sees_nothing_and_can_act_on_nothing(): void
    {
        $transfer = $this->advance($this->requested($this->unitAt($this->owner)), TransferStatus::APPROVED);
        $headers = $this->bearer($this->staffOf($this->third));

        $this->assertSame([], $this->getJson('/api/v1/stock-transfers', $headers)->assertOk()->json('data'));

        // 404, bukan 403: transfer fasilitas lain tidak ada baginya.
        $this->act($headers, $transfer, 'decision', ['status' => 'rejected'])->assertNotFound();
        $this->act($headers, $transfer, 'ship')->assertNotFound();
        $this->act($headers, $transfer, 'receive')->assertNotFound();
        $this->act($headers, $transfer, 'cancel')->assertNotFound();

        $this->assertSame(TransferStatus::APPROVED, $transfer->fresh()?->status);
    }

    public function test_the_list_can_be_narrowed_by_direction(): void
    {
        $outgoing = $this->requested($this->unitAt($this->owner));
        $incoming = app(RequestStockTransfer::class)->handle($this->unitAt($this->third), $this->owner->id, User::factory()->create()->id, null);
        $headers = $this->bearer($this->staffOf($this->owner));

        $this->assertSame([$outgoing->public_id], array_column(
            $this->getJson('/api/v1/stock-transfers?filter[direction]=outgoing', $headers)->assertOk()->json('data'),
            'id',
        ));
        $this->assertSame([$incoming->public_id], array_column(
            $this->getJson('/api/v1/stock-transfers?filter[direction]=incoming', $headers)->assertOk()->json('data'),
            'id',
        ));
        $this->getJson('/api/v1/stock-transfers?filter[direction]=sideways', $headers)->assertStatus(422);
    }

    public function test_a_rejected_transfer_leaves_the_unit_untouched(): void
    {
        $unit = $this->unitAt($this->owner);

        $this->advance($this->requested($unit), TransferStatus::REJECTED);

        $fresh = $unit->fresh();
        $this->assertSame($this->owner->id, $fresh?->facility_id);
        $this->assertSame(BatchStatus::RELEASED, $fresh->status);
    }

    public function test_a_closed_transfer_frees_the_unit_for_a_new_transfer(): void
    {
        $unit = $this->unitAt($this->owner);
        $this->advance($this->requested($unit), TransferStatus::REJECTED);
        $this->advance($this->requested($unit), TransferStatus::CANCELLED);

        // Predikat partial index memuat semua definisi "terbuka" -- dan hanya itu.
        $this->postJson('/api/v1/stock-transfers', ['unit_id' => $unit->public_id], $this->withKey($this->bearer($this->staffOf($this->requester))))
            ->assertCreated();

        $this->assertSame(3, StockTransfer::query()->where('blood_batch_id', $unit->id)->count());
    }

    public function test_a_unit_that_left_stock_after_the_request_cannot_be_approved_or_shipped(): void
    {
        $unit = $this->unitAt($this->owner);
        $requested = $this->requested($unit);
        $unit->forceFill(['status' => BatchStatus::EXPIRED])->save();

        try {
            $this->advance($requested, TransferStatus::APPROVED);
            $this->fail('An expired unit must not be approved for transfer.');
        } catch (StockTransferRejected) {
            $this->assertSame(TransferStatus::REQUESTED, $requested->fresh()?->status);
        }
    }

    public function test_the_open_predicate_matches_the_partial_index(): void
    {
        foreach (TransferStatus::cases() as $status) {
            $unit = $this->unitAt($this->owner);
            DB::table('stock_transfers')->insert($this->rawTransfer($unit, $status));

            $second = fn (): bool => DB::table('stock_transfers')->insert($this->rawTransfer($unit, TransferStatus::REQUESTED));

            if ($status->isOpen()) {
                $this->assertThrowsUniqueViolation($second, $status);
            } else {
                $second();
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_database_rejects_a_transfer_to_the_same_facility(): void
    {
        $this->requested($this->unitAt($this->owner));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('stock_transfers_direction_shape');

        DB::table('stock_transfers')->update(['to_facility_id' => $this->owner->id]);
    }

    public function test_a_malformed_transfer_id_is_404_not_500(): void
    {
        $this->postJson('/api/v1/stock-transfers/bukan-uuid/ship', [], $this->withKey($this->bearer($this->staffOf($this->owner))))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function rawTransfer(BloodBatch $unit, TransferStatus $status): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $unit->id,
            'from_facility_id' => $this->owner->id,
            'to_facility_id' => $this->requester->id,
            'status' => $status->value,
            'requested_by' => User::factory()->create()->id,
            'received_at' => $status === TransferStatus::RECEIVED ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param  callable(): bool  $insert */
    private function assertThrowsUniqueViolation(callable $insert, TransferStatus $status): void
    {
        try {
            // Savepoint: setelah unique violation Postgres membatalkan transaksi luar test.
            DB::transaction(function () use ($insert): void {
                $insert();
            });
            $this->fail("A second open transfer must be rejected while one is {$status->value}.");
        } catch (QueryException $e) {
            $this->assertStringContainsString('stock_transfers_one_open_per_unit', $e->getMessage());
        }
    }
}
