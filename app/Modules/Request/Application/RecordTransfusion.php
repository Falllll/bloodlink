<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodBatch;
use App\Models\BloodRequestAllocation;
use App\Models\Transfusion;
use App\Models\User;
use App\Modules\Request\Application\Exceptions\TransfusionRejected;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mencatat bahwa kantong yang sudah diterbitkan benar-benar masuk ke pasien.
 * TIDAK mengubah status unit: ISSUED terminal, dan transfusi bukan status kesembilan.
 */
final class RecordTransfusion
{
    public function handle(
        BloodRequestAllocation $allocation,
        User $recordedBy,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt = null,
        ?int $volumeMl = null,
        ?string $note = null,
    ): Transfusion {
        return DB::transaction(function () use ($allocation, $recordedBy, $startedAt, $completedAt, $volumeMl, $note): Transfusion {
            // Kunci unit: penerbitan dan pencatatan transfusi tidak boleh saling menyalip.
            // Urutan kunci sama dengan jalur lain: unit dulu, baru alokasi.
            $unit = BloodBatch::query()->whereKey($allocation->blood_batch_id)->lockForUpdate()->firstOrFail();
            BloodRequestAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            // Darah yang belum diterbitkan tidak mungkin sudah masuk ke pasien.
            $status = (string) $unit->getRawOriginal('status');

            if ($status !== ReturnAllocatedUnit::ISSUED) {
                throw TransfusionRejected::unitNotIssued($status);
            }

            // Index unik juga menjaganya; pemeriksaan ini ada supaya pesannya manusiawi.
            if (Transfusion::query()->where('blood_request_allocation_id', $allocation->id)->exists()) {
                throw TransfusionRejected::alreadyRecorded();
            }

            $transfusion = new Transfusion([
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'volume_transfused_ml' => $volumeMl,
                'note' => $note,
            ]);

            try {
                $transfusion->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'blood_request_allocation_id' => $allocation->id,
                    'recorded_by' => $recordedBy->id,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw TransfusionRejected::alreadyRecorded();
            }

            return $transfusion->setRelation('allocation', $allocation);
        });
    }
}
