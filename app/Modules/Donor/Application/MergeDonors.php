<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Deferral;
use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class MergeDonors
{
    public function handle(Donor $source, Donor $target): Donor
    {
        return DB::transaction(function () use ($source, $target): Donor {
            $orderedIds = [$source->id, $target->id];
            sort($orderedIds);

            $locked = Donor::query()
                ->whereIn('id', $orderedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Donor $source */
            $source = $locked[$source->id];
            /** @var Donor $target */
            $target = $locked[$target->id];

            if ($source->merged_into_id !== null || $target->merged_into_id !== null) {
                throw DonorIdentityConflict::mergeConflict('One of the donors is already merged.');
            }

            if ($source->nik_hash !== null && $target->nik_hash !== null && $source->nik_hash !== $target->nik_hash) {
                throw DonorIdentityConflict::mergeConflict('Donors have different NIKs.');
            }

            $source->forceFill([
                'merged_into_id' => $target->id,
                'merged_at' => now(),
            ])->save();

            $target->last_donation_date = $this->later($source->last_donation_date, $target->last_donation_date);
            $target->donation_count = $source->donation_count + $target->donation_count;

            Deferral::query()
                ->where('donor_id', $source->id)
                ->update(['donor_id' => $target->id]);

            if ($target->nik === null && $source->nik !== null) {
                $target->nik = $source->nik;
            }

            if ($target->email === null && $source->email !== null) {
                $target->email = $source->email;
            }

            $target->save();

            return $target->fresh() ?? $target;
        });
    }

    private function later(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
