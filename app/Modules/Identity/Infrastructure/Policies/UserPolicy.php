<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Policies;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Application\StaffRoles;

/**
 * Manajemen akun STAF (kartu 487). Siapa boleh apa, eksplisit:
 *
 * - Operator global (admin ber-facility_id NULL): akun staf di fasilitas mana pun.
 * - Admin yang terikat satu fasilitas: hanya akun di fasilitasnya sendiri, dan hanya
 *   ke fasilitasnya sendiri -- tidak bisa memindahkan petugas keluar atau masuk.
 * - Petugas biasa: tidak sama sekali (tidak punya user.manage).
 *
 * "Akun staf" = akun ber-facility_id YANG berperan staf di team fasilitas itu. Akun donor
 * dan operator global TIDAK pernah dikelola lewat sini: donor mendaftar sendiri, dan
 * operator global dibuat di luar API supaya tidak ada jalur eskalasi lewat endpoint.
 * facility_id saja bukan bukti: donor ber-facility_id pernah bisa dijadikan admin lewat
 * PUT /users/{user}/assignment. Semua mutasi melewati view(), jadi penjaganya di sana.
 *
 * Tidak ada yang boleh mengubah peran/fasilitas atau menonaktifkan AKUNNYA SENDIRI:
 * admin terakhir sebuah fasilitas tidak boleh bisa mengunci dirinya keluar.
 *
 * Nama permission literal: ModIdentity sengaja tidak mengimpor enum Permission di
 * sini (pola FacilityPolicy::verify()).
 */
final class UserPolicy
{
    public function __construct(private readonly StaffRoles $roles) {}

    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor, User $target): bool
    {
        if (! $this->canManage($actor) || $target->facilityId() === null || ! $this->isStaff($target)) {
            return false;
        }

        return $actor->isGlobalOperator() || $target->facilityId() === $actor->facilityId();
    }

    public function create(User $actor, Facility $facility): bool
    {
        return $this->canManage($actor) && $this->mayUseFacility($actor, $facility);
    }

    /** Mengatur peran dan/atau fasilitas akun staf. */
    public function assign(User $actor, User $target, Facility $facility): bool
    {
        return $this->view($actor, $target)
            && $actor->id !== $target->id
            && $this->mayUseFacility($actor, $facility);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->view($actor, $target) && $actor->id !== $target->id;
    }

    public function activate(User $actor, User $target): bool
    {
        return $this->deactivate($actor, $target);
    }

    /**
     * Pemakaian PERTAMA user.manage (kartu 487) -- ada sejak kartu 70, belum pernah
     * dikonsumsi sebelum ini. Diperiksa di team fasilitas aktor, yang di-bind
     * BindFacilityContext per request.
     */
    private function canManage(User $actor): bool
    {
        return $actor->hasPermissionTo('user.manage');
    }

    /** Dibaca di team fasilitas target (StaffRoles), bukan team aktor yang terikat di request. */
    private function isStaff(User $target): bool
    {
        return in_array($this->roles->roleOf($target), StaffRoles::ASSIGNABLE, true);
    }

    private function mayUseFacility(User $actor, Facility $facility): bool
    {
        return $actor->isGlobalOperator() || $facility->id === $actor->facilityId();
    }
}
