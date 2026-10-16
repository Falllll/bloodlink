<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Models\BloodBatch;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Request\Domain\Events\UnitReservationLifted;

/**
 * Mengembalikan unit reserved ke released. BUKAN lewat TransitionBloodBatch:
 * service itu menolak target RELEASED (gerbang rilis kartu 240). Unit reserved
 * sudah pernah lolos gerbang itu -- released_at-nya terisi -- jadi mengembalikannya
 * bukan merilis ulang, melainkan membatalkan pemesanan.
 *
 * Kantong yang kedaluwarsa SELAMA tertahan di reservasi kembali ke EXPIRED, bukan
 * RELEASED: menaruhnya di released berarti mengembalikannya ke daftar kandidat
 * stok. RESERVED -> EXPIRED sudah sah di graf (kartu 360).
 *
 * Sengaja sinkron, BUKAN ShouldQueue: berjalan di transaksi pelepasan reservasi.
 */
final class ReturnReservedUnitToStock
{
    public function handle(UnitReservationLifted $event): void
    {
        $unit = BloodBatch::query()->whereKey($event->bloodBatchId)->lockForUpdate()->firstOrFail();

        // Unit yang sudah keluar stok (issued, discarded, expired) TIDAK dipaksa kembali:
        // kantong yang sudah ditransfusikan tidak boleh "dibatalkan" menjadi tersedia.
        if ($unit->status !== BatchStatus::RESERVED) {
            return;
        }

        // SENGAJA `<`, sama dengan inventory:expire-check: yang habis tepat detik ini belum lewat.
        $to = $unit->expires_at !== null && $unit->expires_at->lt(now())
            ? BatchStatus::EXPIRED
            : BatchStatus::RELEASED;

        $unit->forceFill(['status' => $to])->save();
    }
}
