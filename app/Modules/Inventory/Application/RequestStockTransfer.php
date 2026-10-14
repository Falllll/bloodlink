<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\StockTransfer;
use App\Modules\Inventory\Application\Exceptions\StockTransferRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fasilitas yang BUTUH yang meminta; pemilik unit menyetujui dan mengirim.
 */
final class RequestStockTransfer
{
    public function handle(BloodBatch $unit, int $toFacilityId, int $requestedBy, ?string $reason): StockTransfer
    {
        // Hanya RELEASED: quarantined/testing belum lolos gerbang lab;
        // issued/discarded/expired/separated sudah keluar stok.
        if ($unit->status !== BatchStatus::RELEASED) {
            throw StockTransferRejected::unitNotTransferable($unit->status);
        }

        // CHECK stock_transfers_direction_shape juga menjaganya, tapi 409 domain
        // lebih berguna bagi klien daripada error constraint.
        if ($unit->ownerFacilityId() === $toFacilityId) {
            throw StockTransferRejected::sameFacility();
        }

        try {
            return DB::transaction(function () use ($unit, $toFacilityId, $requestedBy, $reason): StockTransfer {
                $transfer = new StockTransfer(['reason' => $reason]);

                $transfer->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'blood_batch_id' => $unit->id,
                    'from_facility_id' => $unit->ownerFacilityId(),
                    'to_facility_id' => $toFacilityId,
                    'status' => TransferStatus::REQUESTED,
                    'requested_by' => $requestedBy,
                ])->save();

                return $transfer;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'stock_transfers_one_open_per_unit')) {
                throw StockTransferRejected::alreadyOpen();
            }

            throw $e;
        }
    }
}
