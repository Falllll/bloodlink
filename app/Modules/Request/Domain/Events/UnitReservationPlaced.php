<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Unit yang dialokasikan dipesan untuk sebuah permintaan. Didengar SINKRON oleh
 * modul Inventory di dalam transaksi pemanggil: status unit dan baris alokasi
 * berubah bersama atau tidak sama sekali. Penolakan graf naik ke pemanggil.
 */
final class UnitReservationPlaced
{
    public function __construct(
        public readonly int $bloodBatchId,
    ) {}
}
