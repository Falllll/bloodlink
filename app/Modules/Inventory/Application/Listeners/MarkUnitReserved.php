<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\TransitionBloodBatch;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Request\Domain\Events\UnitReservationPlaced;

// Sengaja sinkron, BUKAN ShouldQueue: reservasi menyimpan baris alokasinya di
// transaksi yang sama, jadi kalau transisi ditolak, reservasinya ikut batal.
final class MarkUnitReserved
{
    public function __construct(private readonly TransitionBloodBatch $transition) {}

    public function handle(UnitReservationPlaced $event): void
    {
        // TransitionBloodBatch sudah lockForUpdate + cek graf; tidak ada kunci kedua.
        // RESERVED -> RESERVED tidak ada di graf: unit yang sudah dipesan ditolak di sana.
        $this->transition->handle(BloodBatch::query()->findOrFail($event->bloodBatchId), BatchStatus::RESERVED);
    }
}
