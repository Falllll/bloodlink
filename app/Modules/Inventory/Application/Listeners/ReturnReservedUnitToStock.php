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

        $unit->forceFill(['status' => BatchStatus::RELEASED])->save();
    }
}
