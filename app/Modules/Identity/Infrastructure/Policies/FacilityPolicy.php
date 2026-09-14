<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Policies;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;

final class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Facility $facility): bool
    {
        if ($user->hasRole(Role::ADMIN->value)) {
            return true;
        }

        return $facility->is_active || $facility->id === $user->facility_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::ADMIN->value);
    }

    public function update(User $user, Facility $facility): bool
    {
        return $user->hasRole(Role::ADMIN->value);
    }

    public function deactivate(User $user, Facility $facility): bool
    {
        return $user->hasRole(Role::ADMIN->value);
    }
}
