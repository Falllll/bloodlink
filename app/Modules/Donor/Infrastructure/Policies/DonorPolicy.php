<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure\Policies;

use App\Models\Donor;
use App\Models\User;

final class DonorPolicy
{
    // App\Modules\Identity\Domain\Role tidak diimpor di sini: itu melanggar batas
    // modul yang dijaga deptrac (ModDonor tidak diizinkan bergantung ke DomIdentity).
    // isGlobalOperator() sudah ada di layer Models (boleh diakses ModDonor) dan
    // perilakunya sama persis: hasRole(Role::ADMIN->value).
    public function merge(User $user): bool
    {
        return $user->isGlobalOperator();
    }

    public function recordConsent(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }
}
