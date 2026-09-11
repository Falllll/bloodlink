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

    /** Berhak melihat seluruh fasilitas, bukan hanya miliknya. */
    public function isGlobalOperator(): bool;
}
