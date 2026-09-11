<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * Dipenuhi oleh model yang barisnya dimiliki satu fasilitas.
 */
interface FacilityScoped
{
    /** Nama kolom pemilik fasilitas di tabel model ini. */
    public function facilityColumn(): string;

    /** Nilai kolom itu pada baris ini. */
    public function ownerFacilityId(): ?int;
}
