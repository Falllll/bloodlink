<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Reservasi sebuah unit dilepas (timeout atau manual). Didengar SINKRON oleh
 * modul Inventory: unit yang masih reserved kembali released; unit yang sudah
 * keluar stok (issued/discarded/expired) dibiarkan.
 */
final class UnitReservationLifted
{
    public function __construct(
        public readonly int $bloodBatchId,
    ) {}
}
