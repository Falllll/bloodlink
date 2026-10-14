<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\StockTransfer;
use App\Modules\Inventory\Application\Exceptions\StockTransferRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TransitionStockTransfer
{
    public function handle(
        StockTransfer $transfer,
        TransferStatus $to,
        ?int $decidedBy = null,
        ?string $decisionNote = null,
    ): StockTransfer {
        DB::transaction(function () use ($transfer, $to, $decidedBy, $decisionNote): void {
            // URUTAN KUNCI: baris transfer dulu, baru baris unit. Setiap jalur yang
            // kelak mengunci keduanya wajib memakai urutan yang sama (preseden
            // MergeDonors), atau dua transaksi bisa saling menunggu selamanya.
            $fresh = StockTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->canTransitionTo($to)) {
                throw StockTransferRejected::illegal($fresh->status, $to);
            }

            if ($to === TransferStatus::APPROVED || $to === TransferStatus::SHIPPED || $to === TransferStatus::RECEIVED) {
                $unit = BloodBatch::query()->whereKey($fresh->blood_batch_id)->lockForUpdate()->firstOrFail();

                // Selama transfer terbuka unit bisa kedaluwarsa (kartu 280) atau
                // diterbitkan; menyetujui atau mengirim kantong yang tak lagi layak
                // tidak boleh lolos. Saat RECEIVED kantongnya sudah tiba secara
                // fisik, jadi catatannya harus mengikuti apa pun statusnya.
                if ($to !== TransferStatus::RECEIVED && $unit->status !== BatchStatus::RELEASED) {
                    throw StockTransferRejected::unitNotTransferable($unit->status);
                }

                // TEPAT di sini, dan hanya di sini, unit berpindah fasilitas.
                if ($to === TransferStatus::RECEIVED) {
                    $unit->assignFacility($fresh->to_facility_id)->save();
                }
            }

            $transfer->forceFill([
                'status' => $to,
                $this->timestampColumnFor($to) => now(),
                'decided_by' => $decidedBy ?? $fresh->decided_by,
                'decision_note' => $decisionNote ?? $fresh->decision_note,
            ])->save();
        });

        return $transfer;
    }

    private function timestampColumnFor(TransferStatus $status): string
    {
        return match ($status) {
            TransferStatus::APPROVED => 'approved_at',
            TransferStatus::REJECTED => 'rejected_at',
            TransferStatus::SHIPPED => 'shipped_at',
            TransferStatus::RECEIVED => 'received_at',
            TransferStatus::CANCELLED => 'cancelled_at',
            TransferStatus::REQUESTED => throw new LogicException('REQUESTED is never a transition target.'),
        };
    }
}
