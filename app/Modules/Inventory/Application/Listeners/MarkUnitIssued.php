<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\BloodBatchTransitionRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Request\Domain\Events\AllocatedUnitIssued;

/**
 * Menulis ISSUED untuk unit yang sudah lolos gerbang crossmatch (IssueAllocatedUnit,
 * kartu 350). BUKAN lewat TransitionBloodBatch: service itu menolak ISSUED justru
 * supaya hanya jalur bergerbang ini yang bisa menerbitkan.
 *
 * Sengaja sinkron, BUKAN ShouldQueue: berjalan di transaksi penerbitan.
 */
final class MarkUnitIssued
{
    public function handle(AllocatedUnitIssued $event): void
    {
        $unit = BloodBatch::query()->whereKey($event->bloodBatchId)->lockForUpdate()->firstOrFail();

        // Pertahanan kedua: graf tetap dihormati walau gerbang di atasnya sudah memeriksa.
        if (! $unit->status->canTransitionTo(BatchStatus::ISSUED)) {
            throw BloodBatchTransitionRejected::illegal($unit->status, BatchStatus::ISSUED);
        }

        $unit->forceFill(['status' => BatchStatus::ISSUED])->save();
    }
}
