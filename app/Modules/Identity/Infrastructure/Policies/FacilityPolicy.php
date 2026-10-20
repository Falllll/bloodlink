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
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $facility->is_active || $facility->id === $user->facility_id;
    }

    public function create(User $user): bool
    {
        return $user->isGlobalOperator();
    }

    public function update(User $user, Facility $facility): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->hasRole(Role::ADMIN->value) && $user->facility_id === $facility->id;
    }

    /**
     * Verifikasi: operator global SAJA (admin tanpa fasilitas) -- admin yang terikat
     * satu fasilitas tidak boleh memverifikasi fasilitas mana pun. Pemakaian pertama
     * facility.manage (literal: ModIdentity sengaja tidak mengimpor enum Permission di sini).
     */
    public function verify(User $user, Facility $facility): bool
    {
        return $user->isGlobalOperator() && $user->hasPermissionTo('facility.manage');
    }

    /** Menghidupkan kembali: aturan yang sama dengan menonaktifkan. */
    public function activate(User $user, Facility $facility): bool
    {
        return $this->deactivate($user, $facility);
    }

    public function deactivate(User $user, Facility $facility): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->hasRole(Role::ADMIN->value) && $user->facility_id === $facility->id;
    }
}
