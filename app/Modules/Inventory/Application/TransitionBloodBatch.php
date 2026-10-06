<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\BloodBatchTransitionRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Support\Facades\DB;

final class TransitionBloodBatch
{
    public function handle(BloodBatch $batch, BatchStatus $to, ?string $discardReason = null): BloodBatch
    {
        // Gerbang rilis milik Kartu 240: status update biasa tidak pernah boleh
        // merilis unit, sekalipun grafnya mengizinkan TESTING -> RELEASED.
        if ($to === BatchStatus::RELEASED) {
            throw BloodBatchTransitionRejected::releaseIsGated();
        }

        // SEPARATED hanya lahir bersama turunannya lewat SeparateIntoComponents.
        if ($to === BatchStatus::SEPARATED) {
            throw BloodBatchTransitionRejected::separationIsNotAStatusUpdate();
        }

        DB::transaction(function () use ($batch, $to, $discardReason): void {
            // Baca ulang dengan kunci: dua request yang membawa model basi tidak
            // boleh sama-sama lolos cek transisi (preseden TransitionAppointment).
            $fresh = BloodBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->canTransitionTo($to)) {
                throw BloodBatchTransitionRejected::illegal($fresh->status, $to);
            }

            $batch->forceFill([
                'status' => $to,
                'discard_reason' => $to === BatchStatus::DISCARDED ? $discardReason : $fresh->discard_reason,
            ])->save();
        });

        return $batch;
    }
}
