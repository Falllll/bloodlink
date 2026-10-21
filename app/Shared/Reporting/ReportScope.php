<?php

declare(strict_types=1);

namespace App\Shared\Reporting;

use App\Shared\Auth\FacilityMember;
use Illuminate\Database\Query\Builder;

/**
 * Padanan ScopedToFacility::visibleTo() untuk query agregat lewat DB::table:
 * laporan tidak membaca model, tapi aturan siapa melihat fasilitas mana harus sama.
 */
final class ReportScope
{
    public static function apply(Builder $query, string $facilityColumn, ?FacilityMember $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->facilityId() !== null) {
            return $query->where($facilityColumn, $user->facilityId());
        }

        if ($user->isGlobalOperator()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}
