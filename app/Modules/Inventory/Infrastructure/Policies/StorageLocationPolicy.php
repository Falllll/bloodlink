<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Policies;

use App\Models\StorageLocation;
use App\Models\User;

/**
 * Pola fasilitas sama dengan BloodBatchPolicy. Repo tidak punya
 * inventory.update: menulis (membuat lokasi, mencatat suhu) memakai
 * inventory.create, sama seperti perpindahan status dan hasil lab unit.
 */
final class StorageLocationPolicy
{
    // Nama permission sengaja literal: ModInventory tidak boleh bergantung ke
    // App\Modules\Identity\Domain\Permission (dijaga deptrac).
    private const string CREATE = 'inventory.create';

    private const string VIEW = 'inventory.view';

    /** Gerbang jenis data; baris mana diurus visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, StorageLocation $location): bool
    {
        if (! $user->hasPermissionTo(self::VIEW)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $location->ownerFacilityId();
    }

    /** Lokasi selalu milik satu fasilitas; operator tanpa fasilitas tidak punya tempat menaruhnya. */
    public function create(User $user): bool
    {
        return $user->facilityId() !== null && $user->hasPermissionTo(self::CREATE);
    }

    public function recordReading(User $user, StorageLocation $location): bool
    {
        if (! $user->hasPermissionTo(self::CREATE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $location->ownerFacilityId();
    }
}
