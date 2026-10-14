<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Policies;

use App\Models\BloodBatch;
use App\Models\StockTransfer;
use App\Models\User;

/**
 * Arah peran: fasilitas TUJUAN (yang butuh) meminta dan menerima; fasilitas
 * ASAL (pemilik unit) memutuskan dan mengirim. Urutan cek sama dengan
 * BloodBatchPolicy: izin dulu, lalu operator global, lalu kecocokan fasilitas.
 */
final class StockTransferPolicy
{
    // Nama permission sengaja literal: ModInventory tidak boleh bergantung ke
    // App\Modules\Identity\Domain\Permission (dijaga deptrac).
    private const string CREATE = 'inventory.create';

    private const string VIEW = 'inventory.view';

    private const string RELEASE = 'inventory.release';

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, self::VIEW, [$transfer->from_facility_id, $transfer->to_facility_id]);
    }

    /**
     * Peminta selalu fasilitas tujuan, jadi operator global tanpa fasilitas
     * tidak bisa meminta: tidak ada tempat untuk menerima unitnya.
     */
    public function request(User $user, BloodBatch $unit): bool
    {
        return $user->hasPermissionTo(self::CREATE)
            && $user->facilityId() !== null
            && $user->facilityId() !== $unit->ownerFacilityId();
    }

    public function decide(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, self::RELEASE, [$transfer->from_facility_id]);
    }

    public function ship(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, self::RELEASE, [$transfer->from_facility_id]);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, self::CREATE, [$transfer->to_facility_id]);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->allowed($user, self::CREATE, [$transfer->from_facility_id, $transfer->to_facility_id]);
    }

    /** @param  list<int>  $facilityIds */
    private function allowed(User $user, string $permission, array $facilityIds): bool
    {
        if (! $user->hasPermissionTo($permission)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && in_array($user->facilityId(), $facilityIds, true);
    }
}
