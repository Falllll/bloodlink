<?php

declare(strict_types=1);

namespace App\Shared\Database;

use App\Shared\Auth\FacilityMember;
use Illuminate\Auth\Access\AuthorizationException;

final class FacilityGuard
{
    /**
     * @throws AuthorizationException
     */
    public static function assertVisible(FacilityScoped $record, ?FacilityMember $user): void
    {
        if (! $user) {
            throw new AuthorizationException;
        }

        if ($user->facilityId() !== null && $record->ownerFacilityId() === $user->facilityId()) {
            return;
        }

        if ($user->isGlobalOperator()) {
            return;
        }

        throw new AuthorizationException;
    }
}
