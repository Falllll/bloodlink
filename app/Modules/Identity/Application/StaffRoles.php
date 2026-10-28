<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Role;
use App\Shared\Auth\FacilityScope;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Peran staf yang di-scope per fasilitas lewat Spatie teams (kartu 487).
 *
 * Peran SELALU ditulis di team fasilitas MILIK TARGET, bukan team yang sedang terikat
 * di request (team aktor). Memberi peran tanpa mengikat team target berarti memberi
 * peran di fasilitas yang salah, dan gagalnya sunyi. Team aktor dikembalikan di
 * finally: pemeriksaan izin aktor sesudahnya tidak boleh ikut pindah fasilitas.
 */
final class StaffRoles
{
    /** Peran yang boleh diberikan lewat API ini. Donor mendaftar sendiri; operator global di luar API. */
    public const array ASSIGNABLE = [Role::HOSPITAL_STAFF, Role::ADMIN];

    public function roleOf(User $user): ?Role
    {
        return $this->inTeamOf($user->facilityId(), function () use ($user): ?Role {
            $user->unsetRelation('roles');
            $name = $user->getRoleNames()->first();

            return $name === null ? null : Role::from($name);
        });
    }

    /** Satu peran per akun staf, di team fasilitas akun itu. */
    public function set(User $user, Role $role): void
    {
        $this->inTeamOf($user->facilityId(), function () use ($user, $role): void {
            $user->unsetRelation('roles');
            $user->syncRoles([$role->value]);
        });
    }

    /** Membersihkan seluruh peran akun ini di team fasilitas LAMA-nya (saat dipindah). */
    public function clear(User $user, ?int $facilityId): void
    {
        $this->inTeamOf($facilityId, function () use ($user): void {
            $user->unsetRelation('roles');
            $user->syncRoles([]);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inTeamOf(?int $facilityId, Closure $work): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(FacilityScope::of($facilityId));

        try {
            return $work();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
