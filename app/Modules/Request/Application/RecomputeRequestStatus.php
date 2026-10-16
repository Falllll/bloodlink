<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Modules\Request\Domain\RequestStatus;

/**
 * Menurunkan (atau menaikkan) status permintaan mengikuti jumlah alokasi aktif.
 * Naik/turun lewat graf RequestStatus, tidak pernah ditulis langsung. Status
 * terminal (cancelled, expired) tidak pernah ditimpa.
 *
 * DIPANGGIL DI DALAM transaksi pemanggil yang sudah memegang kunci baris permintaan.
 */
final class RecomputeRequestStatus
{
    public function handle(BloodRequest $request): RequestStatus
    {
        if ($request->status->isTerminal()) {
            return $request->status;
        }

        $active = BloodRequestAllocation::query()
            ->where('blood_request_id', $request->id)
            ->whereNull('released_at')
            ->count();

        $target = match (true) {
            $active === 0 => RequestStatus::OPEN,
            $active < $request->quantity => RequestStatus::PARTIALLY_FULFILLED,
            default => RequestStatus::FULFILLED,
        };

        if ($target !== $request->status && $request->status->canTransitionTo($target)) {
            $request->forceFill(['status' => $target])->save();
        }

        return $request->status;
    }
}
