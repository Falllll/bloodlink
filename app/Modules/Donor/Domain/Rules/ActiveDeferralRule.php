<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Rules;

use App\Modules\Donor\Domain\DeferralType;
use App\Modules\Donor\Domain\DeferralWindow;
use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\EligibilityRule;
use DateTimeImmutable;

final readonly class ActiveDeferralRule implements EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding {
        $activeWindows = array_values(array_filter(
            $snapshot->deferrals,
            fn (DeferralWindow $window): bool => $window->isActiveOn($today),
        ));

        if ($activeWindows === []) {
            return null;
        }

        foreach ($activeWindows as $window) {
            if ($window->type === DeferralType::PERMANENT) {
                return new EligibilityFinding(
                    outcome: EligibilityOutcome::NOT_ELIGIBLE,
                    code: EligibilityFindingCode::ACTIVE_DEFERRAL,
                    retryAfterDate: null,
                );
            }
        }

        $retryAfterDate = null;

        foreach ($activeWindows as $window) {
            $endsOn = $window->endsOn();

            if ($endsOn !== null && ($retryAfterDate === null || $endsOn > $retryAfterDate)) {
                $retryAfterDate = $endsOn;
            }
        }

        return new EligibilityFinding(
            outcome: EligibilityOutcome::NOT_ELIGIBLE,
            code: EligibilityFindingCode::ACTIVE_DEFERRAL,
            retryAfterDate: $retryAfterDate,
        );
    }
}
