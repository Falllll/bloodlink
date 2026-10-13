<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

/**
 * Satu pembacaan suhu jatuh di luar band lokasinya. Payload skalar, bukan model,
 * supaya aman diserialisasi ke queue. Pendengarnya (pengiriman alert) belum ada:
 * kartu 450/455/460.
 */
final class StorageTemperatureOutOfRange
{
    public function __construct(
        public readonly int $storageLocationId,
        public readonly string $readingCelsius,
        public readonly string $occurredAt,
    ) {}
}
