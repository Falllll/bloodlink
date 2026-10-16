<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodBatch;
use App\Models\BloodRequestAllocation;
use App\Modules\Request\Domain\Events\UnitReservationLifted;
use App\Modules\Request\Domain\ReleaseReason;

/**
 * SATU-SATUNYA jalur kompensasi yang mengembalikan unit ke peredaran: pembatalan
 * permintaan, kedaluwarsa permintaan, dan crossmatch incompatible semuanya lewat
 * sini. Melepas alokasi beserta reservasinya sekaligus -- melepas yang satu tanpa
 * yang lain meninggalkan unit reserved tanpa alokasi aktif.
 *
 * DIPANGGIL DI DALAM transaksi pemanggil; tidak membuka DB::transaction sendiri.
 *
 * Status unit dimiliki Inventory (deptrac): unit yang masih reserved dikembalikan
 * lewat UnitReservationLifted, yang didengar SINKRON oleh ReturnReservedUnitToStock.
 * Listener itulah yang memilih RELEASED atau, bila kantongnya sudah lewat
 * expires_at, EXPIRED. Tidak pernah lebih mundur dari RELEASED: unit ini sudah
 * lolos gerbang rilis kartu 240.
 *
 * URUTAN KUNCI sama dengan reservasi dan penerbitan: baris UNIT dulu, baru alokasi.
 */
final class ReturnAllocatedUnit
{
    // Literal, bukan enum: ModRequest tidak boleh mengimpor DomInventory (deptrac).
    // Harus sama dengan BatchStatus::ISSUED -- RequestInventoryLiteralsTest memeriksanya.
    public const string ISSUED = 'issued';

    public function handle(BloodRequestAllocation $allocation, ReleaseReason $reason): BloodRequestAllocation
    {
        $unit = BloodBatch::query()->whereKey($allocation->blood_batch_id)->lockForUpdate()->firstOrFail();
        $fresh = BloodRequestAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

        // Sudah dilepas: bukan error. Pemanggil yang melepas banyak alokasi sekaligus
        // tidak boleh gagal total karena satu baris sudah beres.
        if (! $fresh->isActive()) {
            $allocation->setRawAttributes($fresh->getAttributes(), sync: true);

            return $allocation;
        }

        $status = (string) $unit->getRawOriginal('status');

        // Kantong yang sudah diterbitkan sudah keluar dari bank darah secara fisik.
        // Alokasinya tetap aktif dan ikut terbaca di riwayat.
        if ($status === self::ISSUED) {
            $allocation->setRawAttributes($fresh->getAttributes(), sync: true);

            return $allocation;
        }

        if ($status === IssueAllocatedUnit::RESERVED) {
            event(new UnitReservationLifted($unit->id));
        }

        $wasReserved = $fresh->reserved_at !== null;

        // reserved_at dan reservation_expires_at dikosongkan BERSAMA: CHECK
        // blood_request_allocations_reservation_shape adalah ekuivalensi.
        $fresh->forceFill([
            'released_at' => now(),
            'release_reason' => $reason->value,
            'reserved_at' => null,
            'reservation_expires_at' => null,
            'reservation_release_reason' => $wasReserved ? $reason->value : $fresh->reservation_release_reason,
        ])->save();

        $allocation->setRawAttributes($fresh->getAttributes(), sync: true);

        return $allocation;
    }
}
