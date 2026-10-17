<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Satu baris hasil pindaian stok terdekat (kartu 380): AGREGAT per fasilitas.
 *
 * SENGAJA tanpa identitas kantong -- tidak ada public_id unit, nomor unit, atau
 * kedaluwarsa per kantong. ScopedToFacility menolak secara bawaan, dan pindaian
 * ini tidak melubanginya; detail unit muncul lewat alur transfer kartu 300, di
 * mana fasilitas pemilik yang menyetujui. Menambah properti di sini harus sengaja.
 */
final readonly class NearbyStockEntry
{
    public function __construct(
        public string $facilityPublicId,
        public string $facilityName,
        public int $distanceMeters,
        public int $compatibleUnits,
        public bool $isSelf,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'facility_id' => $this->facilityPublicId,
            'facility_name' => $this->facilityName,
            'distance_meters' => $this->distanceMeters,
            'compatible_units' => $this->compatibleUnits,
            'is_self' => $this->isSelf,
        ];
    }
}
