<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Crossmatch;
use App\Models\User;
use App\Modules\Request\Application\Exceptions\CrossmatchRejected;
use App\Modules\Request\Domain\CrossmatchResult;
use App\Modules\Request\Domain\ReleaseReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mencatat satu hasil uji silang. Hasil INCOMPATIBLE langsung dikompensasi
 * (kartu 360): alokasinya dilepas lewat ReturnAllocatedUnit dan status
 * permintaan dihitung ulang, dalam transaksi yang sama dengan pencatatannya.
 */
final class RecordCrossmatch
{
    public function __construct(
        private readonly ReturnAllocatedUnit $returnUnit,
        private readonly RecomputeRequestStatus $recompute,
    ) {}

    public function handle(
        BloodRequestAllocation $allocation,
        CrossmatchResult $result,
        User $performedBy,
        ?string $method = null,
        ?string $note = null,
    ): Crossmatch {
        return DB::transaction(function () use ($allocation, $result, $performedBy, $method, $note): Crossmatch {
            // URUTAN KUNCI sama dengan CancelBloodRequest: permintaan dulu, baru unit
            // dan alokasi (di ReturnAllocatedUnit).
            $request = BloodRequest::query()->whereKey($allocation->blood_request_id)->lockForUpdate()->firstOrFail();
            $fresh = BloodRequestAllocation::query()->whereKey($allocation->getKey())->firstOrFail();

            if ($fresh->released_at !== null) {
                throw CrossmatchRejected::allocationIsReleased();
            }

            $crossmatch = new Crossmatch([
                'result' => $result,
                'method' => $method,
                'note' => $note,
            ]);

            $crossmatch->forceFill([
                'public_id' => (string) Str::uuid(),
                'blood_request_allocation_id' => $allocation->id,
                'performed_by' => $performedBy->id,
                'performed_at' => now(),
            ])->save();

            // Incompatible: unit ini salah untuk pasien ini. Menahannya tetap reserved
            // menyandera stok yang baik untuk pasien lain.
            // Inconclusive SENGAJA tidak memicu apa pun: pemeriksaannya belum selesai,
            // dan melepasnya membuat unit disambar permintaan lain selagi diperiksa ulang.
            if ($result === CrossmatchResult::INCOMPATIBLE) {
                $this->returnUnit->handle($allocation, ReleaseReason::CROSSMATCH_INCOMPATIBLE);
                $this->recompute->handle($request);
            }

            return $crossmatch->setRelation('allocation', $allocation);
        });
    }
}
