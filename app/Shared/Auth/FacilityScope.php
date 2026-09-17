<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Spatie\Permission\PermissionRegistrar;

final class FacilityScope
{
    /**
     * Nilai facility_id di tabel Spatie untuk penugasan yang tidak terikat
     * fasilitas mana pun. Bukan NULL: kolomnya ikut di primary key pivot.
     */
    public const GLOBAL_SCOPE = 0;

    public static function of(?int $facilityId): int
    {
        return $facilityId ?? self::GLOBAL_SCOPE;
    }

    public static function bind(?int $facilityId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(
            self::of($facilityId)
        );
    }
}
