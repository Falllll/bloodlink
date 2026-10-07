<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure\Policies;

use App\Models\Donor;
use App\Models\User;

final class DonorPolicy
{
    // App\Modules\Identity\Domain\Role tidak diimpor di sini: itu melanggar batas
    // modul yang dijaga deptrac (ModDonor tidak boleh bergantung ke DomIdentity).
    // isGlobalOperator() ada di layer Models (boleh diakses ModDonor) = admin YANG
    // TIDAK terikat fasilitas. Sengaja lebih ketat dari kartu: merge itu operasi
    // lintas fasilitas, jadi admin berfasilitas memang tidak boleh melakukannya.
    // Nama permission sengaja literal, alasannya sama dengan Role di atas.
    private const string VIEW = 'donor.view';

    private const string UPDATE = 'donor.update';

    /** Gerbang jenis data daftar donor; baris mana diurus visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    /**
     * Riwayat deferral adalah data kesehatan: izin donor.view DAN fasilitas yang
     * sama. view() saja hanya mencocokkan fasilitas.
     */
    public function viewDeferrals(User $user, Donor $donor): bool
    {
        return $user->hasPermissionTo(self::VIEW) && $this->view($user, $donor);
    }

    /** Menempatkan atau mencabut deferral: izin donor.update DAN fasilitas yang sama. */
    public function manageDeferrals(User $user, Donor $donor): bool
    {
        return $user->hasPermissionTo(self::UPDATE) && $this->update($user, $donor);
    }

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

    public function recordScreening(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function recordDonation(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function view(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function update(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function updateHealthStatus(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function bookAppointment(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }

    public function transitionAppointment(User $user, Donor $donor): bool
    {
        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() === $donor->registered_facility_id;
    }
}
