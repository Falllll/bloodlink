<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Modules\Request\Application\Exceptions\ReservationRejected;
use App\Modules\Request\Domain\Events\UnitReservationLifted;
use App\Modules\Request\Domain\Events\UnitReservationPlaced;
use Illuminate\Support\Facades\DB;

/**
 * Memesan unit yang sudah dialokasikan (kartu 320) dan melepasnya lagi.
 *
 * Status unit dimiliki modul Inventory: modul ini tidak menyentuhnya langsung
 * (deptrac), melainkan memancarkan UnitReservationPlaced/Lifted yang didengar
 * Inventory SINKRON di transaksi yang sama. Hasilnya tetap satu transaksi:
 * status unit dan baris alokasi berubah bersama atau tidak sama sekali.
 *
 * URUTAN KUNCI di semua jalur: baris UNIT dulu (diambil listener Inventory),
 * baru baris alokasi. Beberapa unit dikunci dalam urutan blood_batch_id.
 */
final class ReserveAllocatedUnits
{
    /**
     * Jendela reservasi default. Angka OPERASIONAL, bukan medis -- tidak ada
     * ambang reservasi di Rujukan Pedoman Medis, jadi ini pilihan kita.
     */
    public const int DEFAULT_WINDOW_HOURS = 48;

    /**
     * Pesan setiap unit yang sudah dialokasikan ke permintaan ini tapi belum dipesan.
     *
     * @return list<BloodRequestAllocation>
     */
    public function handle(BloodRequest $request, ?int $windowHours = null): array
    {
        $expiresAt = now()->addHours($windowHours ?? self::DEFAULT_WINDOW_HOURS);

        return DB::transaction(function () use ($request, $expiresAt): array {
            // orderBy('blood_batch_id') WAJIB: kunci unit diambil dalam urutan ini.
            // Dua permintaan yang memesan dua unit yang sama dengan urutan terbalik
            // akan saling menunggu selamanya. Preseden: MergeDonors (orderBy('id')).
            $allocations = BloodRequestAllocation::query()
                ->where('blood_request_id', $request->id)
                ->whereNull('released_at')
                ->whereNull('reserved_at')
                ->orderBy('blood_batch_id')
                ->get();

            if ($allocations->isEmpty()) {
                throw ReservationRejected::nothingToReserve();
            }

            foreach ($allocations as $allocation) {
                // Listener Inventory: lockForUpdate + graf. Penolakan graf
                // (BloodBatchTransitionRejected, 409) naik apa adanya dan
                // membatalkan SELURUH reservasi ini -- tidak ada pemesanan setengah.
                event(new UnitReservationPlaced($allocation->blood_batch_id));

                $allocation->forceFill([
                    'reserved_at' => now(),
                    'reservation_expires_at' => $expiresAt,
                    'reservation_release_reason' => null,
                ])->save();
            }

            return $allocations->all();
        });
    }

    /** Lepas satu reservasi: unit kembali ke released, alokasinya tetap hidup. */
    public function release(BloodRequestAllocation $allocation, string $reason): BloodRequestAllocation
    {
        // Belum (atau tidak lagi) dipesan: idempoten.
        if ($allocation->reserved_at === null) {
            return $allocation;
        }

        DB::transaction(function () use ($allocation, $reason): void {
            // Kunci unit dulu (di listener), baru alokasi -- urutan yang sama dengan handle().
            event(new UnitReservationLifted($allocation->blood_batch_id));

            $fresh = BloodRequestAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->reserved_at === null) {
                $allocation->setRawAttributes($fresh->getAttributes(), sync: true);

                return;
            }

            $allocation->forceFill([
                'reserved_at' => null,
                'reservation_expires_at' => null,
                'reservation_release_reason' => $reason,
            ])->save();
        });

        return $allocation;
    }
}
