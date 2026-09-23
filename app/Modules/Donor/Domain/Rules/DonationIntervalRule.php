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

final readonly class DonationIntervalRule implements EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding {
        if ($snapshot->lastDonationDate === null) {
            return null;
        }

        $intervalDays = $snapshot->sex === 'female'
            ? WhoEligibilityLimits::INTERVAL_FEMALE_DAYS
            : WhoEligibilityLimits::INTERVAL_MALE_DAYS;

        $retryAfterDate = $snapshot->lastDonationDate->add(new DateInterval("P{$intervalDays}D"));

        if ($today < $retryAfterDate) {
            return new EligibilityFinding(
                outcome: EligibilityOutcome::NOT_ELIGIBLE,
                code: EligibilityFindingCode::DONATION_INTERVAL_NOT_MET,
                retryAfterDate: $retryAfterDate,
            );
        }

        return null;
    }
}
