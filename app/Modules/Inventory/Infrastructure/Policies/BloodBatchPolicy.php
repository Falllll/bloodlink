<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Policies;

use App\Models\BloodBatch;
use App\Models\User;

final class BloodBatchPolicy
{
    // Nama permission sengaja literal: ModInventory tidak boleh bergantung ke
    // App\Modules\Identity\Domain\Permission (dijaga deptrac).
    private const string CREATE = 'inventory.create';

    private const string VIEW = 'inventory.view';

    private const string RELEASE = 'inventory.release';

    /** Gerbang jenis data: boleh melihat inventori sama sekali? Baris mana diurus visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, BloodBatch $batch): bool
    {
        // Izin dulu, sebelum cabang operator global: operator global pun butuh izin.
        if (! $user->hasPermissionTo(self::VIEW)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $batch->ownerFacilityId();
    }

    /** Unit selalu milik satu fasilitas; operator tanpa fasilitas tidak punya tempat menaruhnya. */
    public function create(User $user): bool
    {
        return $user->facilityId() !== null && $user->hasPermissionTo(self::CREATE);
    }

    public function transition(User $user, BloodBatch $batch): bool
    {
        if (! $user->hasPermissionTo(self::CREATE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $batch->ownerFacilityId();
    }

    public function release(User $user, BloodBatch $batch): bool
    {
        if (! $user->hasPermissionTo(self::RELEASE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $batch->ownerFacilityId();
    }

    /** Mencatat hasil lab mengubah data medis unit: syaratnya sama dengan perpindahan status. */
    public function recordLabResult(User $user, BloodBatch $batch): bool
    {
        return $this->transition($user, $batch);
    }
}
