<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;
use Illuminate\Support\Facades\DB;

/**
 * Mengatur peran dan fasilitas satu akun staf (kartu 487).
 *
 * Pindah fasilitas = peran di team LAMA dibersihkan dulu, baru ditulis di team baru;
 * kalau tidak, akun itu diam-diam tetap memegang peran di fasilitas lamanya.
 *
 * Setiap perubahan mencabut SEMUA token Sanctum akun itu: sesi lama tidak boleh tetap
 * memegang izin lama sampai kedaluwarsa. Permintaan yang tidak mengubah apa pun
 * tidak mencabut apa pun dan tidak menulis audit.
 */
final class AssignStaff
{
    public function __construct(
        private readonly StaffRoles $roles,
        private readonly RecordStaffAssignment $record,
    ) {}

    public function handle(User $target, Facility $facility, Role $role): User
    {
        return DB::transaction(function () use ($target, $facility, $role): User {
            $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $oldFacilityId = $target->facilityId();
            $oldRole = $this->roles->roleOf($target);

            if ($oldFacilityId === $facility->id && $oldRole === $role) {
                return $target;
            }

            $oldFacilityPublicId = $oldFacilityId === null
                ? null
                : Facility::query()->whereKey($oldFacilityId)->value('public_id');

            if ($oldFacilityId !== $facility->id) {
                $this->roles->clear($target, $oldFacilityId);
                $target->forceFill(['facility_id' => $facility->id])->save();
            }

            $this->roles->set($target, $role);
            $target->tokens()->delete();

            $this->record->handle(
                $target,
                ['facility_id' => is_string($oldFacilityPublicId) ? $oldFacilityPublicId : null, 'role' => $oldRole?->value],
                ['facility_id' => $facility->public_id, 'role' => $role->value],
            );

            return $target;
        });
    }
}
