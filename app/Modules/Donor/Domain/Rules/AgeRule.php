<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Rules;

use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\EligibilityRule;
use App\Modules\Donor\Domain\WhoEligibilityLimits;
use DateInterval;
use DateTimeImmutable;

final readonly class AgeRule implements EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding {
        $age = $snapshot->ageOn($today);

        if ($age < WhoEligibilityLimits::AGE_MIN_USUAL_YEARS) {
            $eighteenthBirthday = $snapshot->dateOfBirth->add(
                new DateInterval('P'.WhoEligibilityLimits::AGE_MIN_USUAL_YEARS.'Y')
            );

            return new EligibilityFinding(
                outcome: EligibilityOutcome::NOT_ELIGIBLE,
                code: EligibilityFindingCode::AGE_BELOW_MINIMUM,
                retryAfterDate: $eighteenthBirthday,
            );
        }

        if ($age > WhoEligibilityLimits::AGE_MAX_USUAL_YEARS) {
            return new EligibilityFinding(
                outcome: EligibilityOutcome::NOT_ELIGIBLE,
                code: EligibilityFindingCode::AGE_ABOVE_MAXIMUM,
            );
        }

        return null;
    }
}
