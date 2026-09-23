<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\DeferralConflict;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DeferralType;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlaceDeferral
{
    public function handle(
        Donor $donor,
        DeferralReason $reason,
        DateTimeImmutable $anchorAt,
        DeferralSource $source,
        ?int $placedBy = null,
        ?string $note = null,
    ): Deferral {
        if (! $reason->is_active) {
            throw DeferralConflict::inactiveReason($reason->code);
        }

        return DB::transaction(function () use ($donor, $reason, $anchorAt, $source, $placedBy, $note): Deferral {
            $deferral = new Deferral([
                'donor_id' => $donor->id,
                'deferral_reason_id' => $reason->id,
                'type' => $reason->type,
                'anchor_at' => $anchorAt,
                'duration_value' => $reason->default_duration_value,
                'duration_unit' => $reason->default_duration_unit,
                'ends_at' => $this->endsAt($reason, $anchorAt),
                'source' => $source,
                'note' => $note,
            ]);

            $deferral->forceFill([
                'public_id' => (string) Str::uuid(),
                'facility_id' => $donor->registered_facility_id,
                'placed_by' => $placedBy,
            ])->save();

            return $deferral;
        });
    }

    private function endsAt(DeferralReason $reason, DateTimeImmutable $anchorAt): ?DateTimeImmutable
    {
        if ($reason->type === DeferralType::PERMANENT) {
            return null;
        }

        if ($reason->default_duration_value === null || $reason->default_duration_unit === null) {
            return null;
        }

        return $anchorAt->add(new DateInterval(
            $reason->default_duration_unit->toDateIntervalSpec($reason->default_duration_value)
        ));
    }
}
