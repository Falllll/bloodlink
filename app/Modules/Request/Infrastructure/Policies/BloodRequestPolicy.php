<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure\Policies;

use App\Models\BloodRequest;
use App\Models\User;

final class BloodRequestPolicy
{
    // Nama permission sengaja literal: ModRequest tidak boleh bergantung ke
    // App\Modules\Identity\Domain\Permission (dijaga deptrac).
    private const string CREATE = 'request.create';

    private const string VIEW = 'request.view';

    /** Mengeluarkan stok adalah tindakan inventory, bukan tindakan permintaan. */
    private const string RELEASE_STOCK = 'inventory.release';

    /** Gerbang jenis data; baris mana diurus visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, BloodRequest $request): bool
    {
        // Izin dulu, sebelum cabang operator global: operator global pun butuh izin.
        if (! $user->hasPermissionTo(self::VIEW)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }

    /** Permintaan selalu milik satu fasilitas; operator tanpa fasilitas tidak bisa meminta. */
    public function create(User $user): bool
    {
        return $user->facilityId() !== null && $user->hasPermissionTo(self::CREATE);
    }

    /** Melihat kandidat dan mengalokasikan unit dari stok fasilitas peminta. */
    public function allocate(User $user, BloodRequest $request): bool
    {
        if (! $user->hasPermissionTo(self::RELEASE_STOCK)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }
}
