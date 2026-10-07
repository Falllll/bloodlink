<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\AboRhDetermination;
use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\LabResultRejected;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menetapkan golongan ABO/Rh unit. blood_group/rh_factor di unit -- dan di
 * turunannya -- hanya salinan dari baris penetapan ini. Status unit tidak
 * pernah disentuh. Kompatibilitas golongan adalah Kartu 315, bukan di sini.
 */
final class RecordAboRhDetermination
{
    public function handle(
        BloodBatch $unit,
        string $bloodGroup,
        string $rhFactor,
        DateTimeImmutable $determinedAt,
        ?int $recordedBy,
    ): AboRhDetermination {
        LabUnitGuard::assertTestable($unit);

        $determination = new AboRhDetermination([
            'blood_group' => $bloodGroup,
            'rh_factor' => $rhFactor,
            'determined_at' => $determinedAt,
        ]);

        $determination->forceFill([
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $unit->id,
            'donation_id' => $unit->donation_id,
            'recorded_by' => $recordedBy,
        ]);

        try {
            DB::transaction(function () use ($determination, $unit, $bloodGroup, $rhFactor): void {
                $determination->save();

                // Per model, bukan update massal, supaya salinannya tercatat di audit log.
                foreach ([$unit, ...$unit->children()->get()] as $batch) {
                    $batch->forceFill(['blood_group' => $bloodGroup, 'rh_factor' => $rhFactor])->save();
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'abo_rh_determinations_blood_batch_id_unique')) {
                throw LabResultRejected::duplicate();
            }

            throw $e;
        }

        return $determination;
    }
}
