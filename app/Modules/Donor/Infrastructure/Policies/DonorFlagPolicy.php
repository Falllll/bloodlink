<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure\Policies;

use App\Models\Donor;
use App\Models\DonorFlag;
use App\Models\User;

/**
 * Flag administratif donor (kartu 430). Didaftarkan MANUAL lewat Gate::policy() di
 * AppServiceProvider: policy di luar app/Policies tidak ditemukan auto-discovery,
 * dan lupa mendaftarkannya berarti semua orang ditolak -- termasuk admin.
 */
final class DonorFlagPolicy
{
    // Literal: ModDonor tidak boleh bergantung ke DomIdentity (deptrac).
    private const string UPDATE = 'donor.update';

    /** Melihat flag: donor.update, dan operator global atau fasilitas tempat donor terdaftar. */
    public function viewAny(User $user, Donor $donor): bool
    {
        if (! $user->hasPermissionTo(self::UPDATE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $donor->ownerFacilityId();
    }

    /** Menempatkan sanksi berlaku di seluruh sistem: hanya operator global. */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(self::UPDATE) && $user->isGlobalOperator();
    }

    public function lift(User $user, DonorFlag $flag): bool
    {
        return $user->hasPermissionTo(self::UPDATE) && $user->isGlobalOperator();
    }
}
