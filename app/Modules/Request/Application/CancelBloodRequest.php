<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Modules\Request\Application\Exceptions\BloodRequestNotAllocatable;
use App\Modules\Request\Domain\ReleaseReason;
use App\Modules\Request\Domain\RequestStatus;
use Illuminate\Support\Facades\DB;

/**
 * Saga pembatalan (kartu 360): menutup permintaan dan mengembalikan setiap unit
 * yang belum terbit ke peredaran. "Saga" hanya nama polanya -- semua yang
 * dikompensasi hidup di satu PostgreSQL, jadi kompensasinya SATU transaksi yang
 * rollback sendiri, bukan koreografi lewat broker.
 *
 * Unit yang sudah issued tidak ditarik: alokasinya dibiarkan aktif sebagai riwayat.
 *
 * URUTAN KUNCI: baris permintaan dulu (sama dengan AllocateUnitsFefo), lalu per
 * alokasi baris unit lalu baris alokasi dalam urutan blood_batch_id (preseden
 * MergeDonors). Selama kunci permintaan dipegang, alokasi baru tidak bisa masuk.
 */
final class CancelBloodRequest
{
    public function __construct(
        private readonly ReturnAllocatedUnit $returnUnit,
    ) {}

    /** Sekali jalan: pembatalan kedua ditolak 409, bukan dianggap sukses diam-diam. */
    public function handle(BloodRequest $request, string $reason): BloodRequest
    {
        return $this->close($request, RequestStatus::CANCELLED, ReleaseReason::REQUEST_CANCELLED, [
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * Jalur EXPIRED untuk permintaan yang lewat needed_by. Penjadwalnya (command
     * harian) di luar lingkup kartu 360 -- service ini belum dipanggil siapa pun.
     */
    public function expire(BloodRequest $request): BloodRequest
    {
        return $this->close($request, RequestStatus::EXPIRED, ReleaseReason::REQUEST_EXPIRED, []);
    }

    /** @param  array<string, mixed>  $attributes */
    private function close(BloodRequest $request, RequestStatus $to, ReleaseReason $reason, array $attributes): BloodRequest
    {
        DB::transaction(function () use ($request, $to, $reason, $attributes): void {
            $fresh = BloodRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->canTransitionTo($to)) {
                throw $to === RequestStatus::CANCELLED
                    ? BloodRequestNotAllocatable::cannotCancel($fresh->status)
                    : BloodRequestNotAllocatable::cannotExpire($fresh->status);
            }

            // Tanpa lockForUpdate di sini: ReturnAllocatedUnit mengunci unit lalu
            // alokasinya sendiri, dalam urutan yang sama dengan reservasi dan penerbitan.
            // Mengunci alokasi lebih dulu akan membalik urutan itu.
            $allocations = BloodRequestAllocation::query()
                ->where('blood_request_id', $fresh->id)
                ->whereNull('released_at')
                ->orderBy('blood_batch_id')
                ->get();

            foreach ($allocations as $allocation) {
                $this->returnUnit->handle($allocation, $reason);
            }

            $fresh->forceFill(['status' => $to] + $attributes)->save();

            $request->setRawAttributes($fresh->getAttributes(), sync: true);
        });

        return $request;
    }
}
