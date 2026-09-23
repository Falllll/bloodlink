<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Dipenuhi oleh model pengguna. Ada supaya app/Shared tidak perlu mengenal
 * App\Models\User -- kalau ia mengenalnya, Shared dan Models saling bergantung.
 */
interface FacilityMember
{
    /** Fasilitas tempat pengguna ini bekerja; null berarti tidak terikat satu pun. */
    public function facilityId(): ?int;

    /**
     * Berhak melihat seluruh fasilitas, bukan hanya miliknya.
     *
     * Dua syarat wajib, keduanya harus benar: tidak terikat fasilitas mana pun
     * (facilityId() === null) DAN ber-role admin. Keanggotaan role saja tidak
     * pernah cukup, karena role di proyek ini ter-scope per fasilitas (Spatie
     * teams) -- admin yang terikat satu fasilitas tetap "hasRole(admin)" true,
     * tapi bukan operator global.
     */
    public function isGlobalOperator(): bool;
}
