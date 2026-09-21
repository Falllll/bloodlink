<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use App\Models\DonorConsent;
use App\Modules\Donor\Domain\ConsentPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordDonorConsent
{
    /** @param  list<ConsentPurpose>  $purposes */
    public function grant(Donor $donor, array $purposes, ?int $recordedBy, ?string $ip): DonorConsent
    {
        return DB::transaction(function () use ($donor, $purposes, $recordedBy, $ip): DonorConsent {
            DonorConsent::query()
                ->where('donor_id', $donor->id)
                ->whereNull('revoked_at')
                ->get()
                ->each(fn (DonorConsent $previous) => $this->revoke($previous));

            $consent = new DonorConsent([
                'donor_id' => $donor->id,
                'questionnaire_version' => config('privacy.consent.questionnaire_version'),
                'purposes' => array_values(array_unique(array_map(
                    fn (ConsentPurpose $purpose): string => $purpose->value,
                    $purposes,
                ))),
                'granted_at' => now(),
                'recorded_by' => $recordedBy,
                'ip' => $ip,
            ]);

            $consent->forceFill([
                'public_id' => (string) Str::uuid(),
                'facility_id' => $donor->registered_facility_id,
            ])->save();

            return $consent;
        });
    }

    public function revoke(DonorConsent $consent): DonorConsent
    {
        if ($consent->revoked_at === null) {
            $consent->forceFill(['revoked_at' => now()])->save();
        }

        return $consent;
    }
}
