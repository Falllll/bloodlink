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

        if ($user->isGlobalOperator()) {
            return;
        }

        if ($user->facilityId() === null) {
            throw new AuthorizationException;
        }

        if ($record->ownerFacilityId() !== $user->facilityId()) {
            throw new AuthorizationException;
        }
    }
}
