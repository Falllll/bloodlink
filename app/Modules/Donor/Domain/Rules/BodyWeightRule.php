<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Rules;

use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\EligibilityRule;
use App\Modules\Donor\Domain\WhoEligibilityLimits;
use DateTimeImmutable;

final readonly class BodyWeightRule implements EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding {
        if ($snapshot->weightKg === null) {
            return new EligibilityFinding(
                outcome: EligibilityOutcome::UNDETERMINED,
                code: EligibilityFindingCode::DATA_MISSING,
            );
        }

        $minimumKg = $snapshot->plannedVolumeMl <= 350
            ? WhoEligibilityLimits::WEIGHT_MIN_350ML_KG
            : WhoEligibilityLimits::WEIGHT_MIN_450ML_KG;

        if ($snapshot->weightKg < $minimumKg) {
            return new EligibilityFinding(
                outcome: EligibilityOutcome::NOT_ELIGIBLE,
                code: EligibilityFindingCode::BODY_WEIGHT_BELOW_MINIMUM,
            );
        }

        return null;
    }
}
