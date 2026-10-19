<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Events;

/**
 * Koordinat donor berubah. NULL di kedua koordinat berarti lokasi DIHAPUS --
 * bukan titik (0,0), yang menaruh donor di Teluk Guinea. Payload primitif saja.
 *
 * Per kartu 390 belum ada jalur Application yang menulis donors.location, jadi
 * belum ada yang memancarkan event ini; donor:reconcile-geo satu-satunya jalan
 * masuk data ke indeks sampai jalur tulis itu dibuat.
 */
final class DonorLocationChanged
{
    public function __construct(
        public readonly int $donorId,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {}
}
