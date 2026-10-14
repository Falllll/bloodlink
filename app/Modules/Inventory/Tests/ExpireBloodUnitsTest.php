<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\BloodBatchEvent;
use App\Models\Facility;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\Events\BloodUnitsNearingExpiry;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TtiTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ExpireBloodUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(TtiTestTypeSeeder::class);

        // Presisi detik, sama dengan kolom timestamp: batas "tepat now()" tidak goyah oleh mikrodetik.
        $this->freezeSecond();
    }

    /**
     * blood_batches_expiry_provenance: expires_at butuh storage_profile_id (diisi
     * factory) dan expires_at > collected_at -- jadi collected_at dimundurkan jauh.
     */
    private function unitExpiringAt(?Carbon $expiresAt, BatchStatus $status = BatchStatus::RELEASED, ?int $facilityId = null): BloodBatch
    {
        $unit = BloodBatch::factory()->create([
            'component' => 'packed_red_cells',
            ...($facilityId !== null ? ['facility_id' => $facilityId] : []),
        ]);

        $unit->forceFill([
            'collected_at' => now()->subDays(90),
            'expires_at' => $expiresAt,
            'status' => $status,
            // blood_batches_released_shape: released/reserved/issued membawa released_at.
            'released_at' => in_array($status, [BatchStatus::RELEASED, BatchStatus::RESERVED, BatchStatus::ISSUED], true) ? now()->subDays(60) : null,
            // blood_batches_discarded_shape.
            'discard_reason' => $status === BatchStatus::DISCARDED ? 'Kantong bocor.' : null,
            // blood_batches_separated_shape.
            'separated_at' => $status === BatchStatus::SEPARATED ? now()->subDays(80) : null,
        ])->save();

        return $unit;
    }

    private function statusOf(BloodBatch $unit): ?BatchStatus
    {
        return $unit->fresh()?->status;
    }

    public function test_an_overdue_unit_becomes_expired(): void
    {
        $unit = $this->unitExpiringAt(now()->subDay());

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        $this->assertSame(BatchStatus::EXPIRED, $this->statusOf($unit));
        // Lewat TransitionBloodBatch, jadi perekam kartu 260 ikut menangkapnya.
        $this->assertTrue(BloodBatchEvent::query()
            ->where('blood_batch_id', $unit->id)
            ->where('from_status', 'released')
            ->where('to_status', 'expired')
            ->exists());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Event::fake([BloodUnitsNearingExpiry::class]);
        $overdue = $this->unitExpiringAt(now()->subDay());
        $this->unitExpiringAt(now()->addDays(2));

        $this->artisan('inventory:expire-check')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain($overdue->public_id)
            ->assertSuccessful();

        $this->assertSame(BatchStatus::RELEASED, $this->statusOf($overdue));
        $this->assertSame(0, BloodBatch::query()->where('status', 'expired')->count());
        Event::assertNotDispatched(BloodUnitsNearingExpiry::class);
    }

    public function test_a_unit_expiring_exactly_now_is_not_yet_expired(): void
    {
        $exactlyNow = $this->unitExpiringAt(now());
        $oneSecondAgo = $this->unitExpiringAt(now()->subSecond());

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        $this->assertSame(BatchStatus::RELEASED, $this->statusOf($exactlyNow));
        $this->assertSame(BatchStatus::EXPIRED, $this->statusOf($oneSecondAgo));
    }

    public function test_a_unit_without_an_expiry_date_is_never_touched(): void
    {
        $unit = $this->unitExpiringAt(null);

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        $this->assertSame(BatchStatus::RELEASED, $this->statusOf($unit));
    }

    public function test_an_issued_unit_is_left_alone(): void
    {
        $unit = $this->unitExpiringAt(now()->subDay(), BatchStatus::ISSUED);

        // Tersaring SEBELUM transisi, bukan ditolak TransitionBloodBatch lalu dilewati.
        $this->artisan('inventory:expire-check', ['--force' => true])
            ->expectsOutputToContain('Menandai 0 unit kedaluwarsa')
            ->doesntExpectOutputToContain('Lewati')
            ->assertSuccessful();

        $this->assertSame(BatchStatus::ISSUED, $this->statusOf($unit));
    }

    public function test_a_discarded_unit_is_left_alone(): void
    {
        $unit = $this->unitExpiringAt(now()->subDay(), BatchStatus::DISCARDED);

        // Tersaring SEBELUM transisi, bukan ditolak TransitionBloodBatch lalu dilewati.
        $this->artisan('inventory:expire-check', ['--force' => true])
            ->expectsOutputToContain('Menandai 0 unit kedaluwarsa')
            ->doesntExpectOutputToContain('Lewati')
            ->assertSuccessful();

        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::DISCARDED, $fresh?->status);
        $this->assertSame('Kantong bocor.', $fresh->discard_reason);
    }

    public function test_every_open_status_is_expired_and_every_terminal_one_is_not(): void
    {
        $units = [];
        foreach (BatchStatus::cases() as $status) {
            $units[$status->value] = $this->unitExpiringAt(now()->subDay(), $status);
        }

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        foreach (BatchStatus::cases() as $status) {
            $expected = $status->canTransitionTo(BatchStatus::EXPIRED) ? BatchStatus::EXPIRED : $status;
            $this->assertSame($expected, $this->statusOf($units[$status->value]), "from {$status->value}");
        }
    }

    public function test_running_twice_transitions_nothing_the_second_time(): void
    {
        $this->unitExpiringAt(now()->subDay());
        $this->unitExpiringAt(now()->subDays(3), BatchStatus::QUARANTINED);

        $this->artisan('inventory:expire-check', ['--force' => true])
            ->expectsOutputToContain('Menandai 2 unit kedaluwarsa')
            ->assertSuccessful();

        $events = BloodBatchEvent::query()->where('to_status', 'expired')->count();

        $this->artisan('inventory:expire-check', ['--force' => true])
            ->expectsOutputToContain('Menandai 0 unit kedaluwarsa')
            ->assertSuccessful();

        $this->assertSame($events, BloodBatchEvent::query()->where('to_status', 'expired')->count());
    }

    public function test_near_expiry_emits_one_event_per_facility(): void
    {
        Event::fake([BloodUnitsNearingExpiry::class]);
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $a1 = $this->unitExpiringAt(now()->addDays(1), facilityId: $facilityA->id);
        $a2 = $this->unitExpiringAt(now()->addDays(3), facilityId: $facilityA->id);
        $b1 = $this->unitExpiringAt(now()->addDays(2), facilityId: $facilityB->id);
        $this->unitExpiringAt(now()->addDays(30), facilityId: $facilityB->id);

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        Event::assertDispatchedTimes(BloodUnitsNearingExpiry::class, 2);
        Event::assertDispatched(BloodUnitsNearingExpiry::class, function (BloodUnitsNearingExpiry $event) use ($facilityA, $a1, $a2): bool {
            $ids = $event->unitPublicIds;
            sort($ids);
            $expected = [$a1->public_id, $a2->public_id];
            sort($expected);

            return $event->facilityId === $facilityA->id
                && $event->withinDays === 7
                && $ids === $expected
                && $event->occurredAt === now()->toAtomString();
        });
        Event::assertDispatched(
            BloodUnitsNearingExpiry::class,
            fn (BloodUnitsNearingExpiry $event): bool => $event->facilityId === $facilityB->id && $event->unitPublicIds === [$b1->public_id],
        );
    }

    public function test_near_expiry_does_not_change_any_status(): void
    {
        $soon = $this->unitExpiringAt(now()->addDays(3));
        $quarantined = $this->unitExpiringAt(now()->addDays(3), BatchStatus::QUARANTINED);

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        $this->assertSame(BatchStatus::RELEASED, $this->statusOf($soon));
        $this->assertSame(BatchStatus::QUARANTINED, $this->statusOf($quarantined));
    }

    public function test_a_unit_already_out_of_stock_is_not_signalled(): void
    {
        Event::fake([BloodUnitsNearingExpiry::class]);
        $this->unitExpiringAt(now()->addDays(2), BatchStatus::ISSUED);
        $this->unitExpiringAt(now()->addDays(2), BatchStatus::DISCARDED);

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();

        Event::assertNotDispatched(BloodUnitsNearingExpiry::class);
    }

    public function test_the_days_option_overrides_the_default_window(): void
    {
        Event::fake([BloodUnitsNearingExpiry::class]);
        $unit = $this->unitExpiringAt(now()->addDays(10));

        $this->artisan('inventory:expire-check', ['--force' => true])->assertSuccessful();
        Event::assertNotDispatched(BloodUnitsNearingExpiry::class);

        $this->artisan('inventory:expire-check', ['--force' => true, '--days' => 14])->assertSuccessful();
        Event::assertDispatched(
            BloodUnitsNearingExpiry::class,
            fn (BloodUnitsNearingExpiry $event): bool => $event->withinDays === 14 && $event->unitPublicIds === [$unit->public_id],
        );
    }

    public function test_a_days_option_below_one_is_rejected(): void
    {
        Event::fake([BloodUnitsNearingExpiry::class]);
        $overdue = $this->unitExpiringAt(now()->subDay());

        $this->artisan('inventory:expire-check', ['--force' => true, '--days' => 0])->assertFailed();
        $this->artisan('inventory:expire-check', ['--force' => true, '--days' => 'abc'])->assertFailed();

        // Gagal validasi berarti tidak ada yang ditulis sama sekali.
        $this->assertSame(BatchStatus::RELEASED, $this->statusOf($overdue));
        Event::assertNotDispatched(BloodUnitsNearingExpiry::class);
    }
}
