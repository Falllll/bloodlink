<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequestAllocation;
use App\Models\Crossmatch;
use App\Models\User;
use App\Modules\Request\Application\Exceptions\CrossmatchRejected;
use App\Modules\Request\Domain\CrossmatchResult;
use Illuminate\Support\Str;

/**
 * Mencatat satu hasil uji silang. Pencatatan ya pencatatan: TIDAK mengubah status
 * unit maupun alokasi apa pun. Hasil yang gagal tidak menarik unitnya (saga kartu 360).
 */
final class RecordCrossmatch
{
    public function handle(
        BloodRequestAllocation $allocation,
        CrossmatchResult $result,
        User $performedBy,
        ?string $method = null,
        ?string $note = null,
    ): Crossmatch {
        if ($allocation->released_at !== null) {
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

        return $crossmatch->setRelation('allocation', $allocation);
    }
}
