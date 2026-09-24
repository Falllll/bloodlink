<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use Illuminate\Support\Facades\DB;

final class UpdateDonorHealthStatus
{
    public function handle(
        Donor $donor,
        ?float $weightKg = null,
        ?string $bloodGroup = null,
        ?string $rhFactor = null,
    ): Donor {
        if ($donor->merged_into_id !== null) {
            throw DonorIdentityConflict::mergeConflict(
                "This donor record has been merged into {$donor->mergedInto?->public_id}."
            );
        }

        DB::transaction(function () use ($donor, $weightKg, $bloodGroup, $rhFactor): void {
            if ($weightKg !== null) {
                $donor->weight_kg = $weightKg;
            }

            $forced = [];

            if ($bloodGroup !== null) {
                $forced['blood_group'] = $bloodGroup;
            }

            if ($rhFactor !== null) {
                $forced['rh_factor'] = $rhFactor;
            }

            if ($forced !== []) {
                $donor->forceFill($forced);
            }

            $donor->save();
        });

        return $donor;
    }
}
