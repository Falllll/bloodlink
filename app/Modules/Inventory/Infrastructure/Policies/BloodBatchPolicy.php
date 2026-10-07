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

    public function view(User $user, BloodBatch $batch): bool
    {
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

    /** Mencatat hasil lab mengubah data medis unit: syaratnya sama dengan perpindahan status. */
    public function recordLabResult(User $user, BloodBatch $batch): bool
    {
        return $this->transition($user, $batch);
    }
}
