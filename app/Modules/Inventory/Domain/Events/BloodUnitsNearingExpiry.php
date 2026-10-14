<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

/**
 * Dipancarkan sekali per fasilitas yang punya unit mendekati kedaluwarsa, bukan
 * sekali per unit. Payload skalar supaya aman diserialisasi ke queue. Belum ada
 * listener -- pengirimannya milik fase F5 (Kartu 450/455/460).
 */
final class BloodUnitsNearingExpiry
{
    /** @param  list<string>  $unitPublicIds */
    public function __construct(
        public readonly int $facilityId,
        public readonly int $withinDays,
        public readonly array $unitPublicIds,
        public readonly string $occurredAt,
    ) {}
}
